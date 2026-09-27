<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissGuideStatus;
use App\Domains\Tiss\Models\{TissEntityOperatorContract, TissGuide};
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, ResolveTissVersionService};
use App\Support\Database\UniqueViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Log};

/**
 * Cria a guia TISS com número do prestador GUI-AAAAMM-NNNNNN, sequencial por
 * clínica × operadora × mês.
 *
 * Concorrência (antes: dois faturamentos em paralelo — ou um lote longo e um
 * individual — liam o mesmo "último número" e o perdedor estourava o índice
 * único => HTTP 500, lote inteiro revertido):
 *  1. a numeração é serializada por clínica × operadora × mês até o fim da
 *     transação do chamador;
 *  2. o próximo número conta guias excluídas (soft delete), que o índice único
 *     continua vendo, e é numérico (não repete depois de 999999);
 *  3. colisão residual (escritor fora do lock, ex.: instância antiga durante o
 *     deploy) => nova tentativa limitada, cada uma em SAVEPOINT próprio.
 */
class CreateTissGuideAction
{
    private const NUMBER_PREFIX = 'GUI';

    /** Largura com zeros à esquerda: dentro dela a ordem de texto é a numérica. */
    private const NUMBER_MIN_DIGITS = 6;

    /** Sequenciais maiores não cabem em int: tratados como fora do padrão. */
    private const NUMBER_MAX_DIGITS = 18;

    /** Tentativas quando o número gerado colide (o lock já evita no fluxo normal). */
    private const MAX_NUMBER_ATTEMPTS = 3;

    private const NUMBER_LOCK_NAMESPACE = 'tiss_guide_number';

    private const NUMBER_UNIQUE_INDEX = 'tiss_guides_entity_operator_provider_number_unique';

    public function __construct(
        private readonly ResolveTissVersionService $resolveVersionService,
        private readonly AddTissGuideItemAction $addTissGuideItemAction,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * `guide_number_provider` do payload só chega por código interno (nenhum
     * request HTTP o repassa) e nunca é trocado: se colidir, a exceção sobe.
     *
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): TissGuide
    {
        $numberGenerated = ! isset($payload['guide_number_provider']);

        for ($attempt = 1;; $attempt++) {
            try {
                // Transação POR tentativa: no PostgreSQL o INSERT que colide aborta a
                // transação (ou o savepoint, quando o chamador já abriu uma).
                return DB::transaction(fn (): TissGuide => $this->create($payload));
            } catch (UniqueConstraintViolationException $e) {
                // Pelo nome do índice na mensagem do DRIVER, em qualquer idioma do
                // servidor — não por texto em qualquer lugar da mensagem (o SQL e
                // os bindings, com dados digitados, também estão lá).
                if (
                    ! $numberGenerated
                    || $attempt >= self::MAX_NUMBER_ATTEMPTS
                    || ! UniqueViolation::violates($e, self::NUMBER_UNIQUE_INDEX)
                ) {
                    throw $e;
                }

                Log::warning('tiss.guide.number_collision', [
                    'entity_id'   => $payload['entity_id'] ?? null,
                    'operator_id' => $payload['operator_id'] ?? null,
                    'attempt'     => $attempt,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function create(array $payload): TissGuide
    {
        $entityId = (string) ($payload['entity_id'] ?? session('selected_entity_id'));

        $contract = null;

        if (! empty($payload['contract_id'])) {
            $contract = TissEntityOperatorContract::query()
                ->forEntity($entityId)
                ->where('id', $payload['contract_id'])
                ->first();
        }

        $version = $this->resolveVersionService->resolve(
            explicitVersionCode: $payload['version_code'] ?? null,
            contract: $contract,
        );

        $guide = TissGuide::query()->create([
            'entity_id'               => $entityId,
            'operator_id'             => $payload['operator_id'],
            'contract_id'             => $contract?->id,
            'version_id'              => $version->id,
            'patient_id'              => $payload['patient_id'] ?? null,
            'doctor_id'               => $payload['doctor_id'] ?? null,
            'schedule_id'             => $payload['schedule_id'] ?? null,
            'medical_record_id'       => $payload['medical_record_id'] ?? null,
            'guide_type'              => $payload['guide_type'] ?? ($contract?->default_guide_type ?? 'consultation'),
            'guide_number_provider'   => $payload['guide_number_provider'] ?? $this->nextGuideNumber($entityId, (string) $payload['operator_id']),
            'guide_number_operator'   => $payload['guide_number_operator'] ?? null,
            'external_attendance_id'  => $payload['external_attendance_id'] ?? null,
            'status'                  => $payload['status'] ?? TissGuideStatus::Draft->value,
            'attendance_date'         => $payload['attendance_date'] ?? now()->toDateString(),
            'execution_date'          => $payload['execution_date'] ?? null,
            'due_date'                => $payload['due_date'] ?? null,
            'beneficiary_card_number' => $payload['beneficiary_card_number'] ?? null,
            'beneficiary_name'        => $payload['beneficiary_name'] ?? null,
            'beneficiary_plan'        => $payload['beneficiary_plan'] ?? null,
            'authorization_number'    => $payload['authorization_number'] ?? null,
            'clinical_indication'     => $payload['clinical_indication'] ?? null,
            'payload'                 => $payload['payload'] ?? null,
            'errors'                  => null,
            'total_amount'            => 0,
            'denied_amount'           => 0,
            'paid_amount'             => 0,
        ]);

        foreach (($payload['items'] ?? []) as $itemPayload) {
            $this->addTissGuideItemAction->__invoke($guide, is_array($itemPayload) ? $itemPayload : []);
        }

        $guide->refresh();

        $this->logStatusTransitionService->record(
            entityId: (string) $guide->entity_id,
            contextType: 'guide',
            contextId: (string) $guide->id,
            currentStatus: (string) $guide->status->value,
            reason: 'Guia TISS criada.',
        );

        return $guide;
    }

    private function nextGuideNumber(string $entityId, string $operatorId): string
    {
        $prefix = sprintf('%s-%s-', self::NUMBER_PREFIX, now()->format('Ym'));

        $this->lockNumbering($entityId, $operatorId, $prefix);

        // Sem escopos globais: inclui guias excluídas (soft delete) e ignora o
        // tenant da sessão — exatamente o que o índice único enxerga.
        $numbers = TissGuide::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('operator_id', $operatorId)
            ->where('guide_number_provider', 'like', $prefix . '%');

        $next = $this->lastSequence($numbers, $prefix) + 1;

        return $prefix . str_pad((string) $next, self::NUMBER_MIN_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Caminho normal (O(1), desce pelo índice único): o maior número em ordem
     * de texto tem a largura padrão e não é 999999 => é também o maior
     * numericamente. Senão (largura esgotada: "…-999999" vem depois de
     * "…-1000000" no texto; ou número fora do padrão no topo) compara
     * numericamente os números do mês, ignorando os fora do padrão.
     *
     * @param Builder<TissGuide> $numbers
     */
    private function lastSequence(Builder $numbers, string $prefix): int
    {
        $column = 'guide_number_provider';
        $top    = (clone $numbers)->orderByDesc($column)->value($column);

        if ($top === null) {
            return 0;
        }

        $suffix = substr((string) $top, strlen($prefix));

        if (preg_match(sprintf('/^\d{%d}$/', self::NUMBER_MIN_DIGITS), $suffix) === 1 && trim($suffix, '9') !== '') {
            return (int) $suffix;
        }

        $pattern = sprintf('/^%s(\d{1,%d})$/', preg_quote($prefix, '/'), self::NUMBER_MAX_DIGITS);

        return (int) $numbers->pluck($column)
            ->map(fn (string $number): int => preg_match($pattern, $number, $match) === 1 ? (int) $match[1] : 0)
            ->max();
    }

    /**
     * PostgreSQL: advisory lock de transação — só quem numera guias desta
     * clínica × operadora × mês espera, até o commit do chamador. Demais
     * drivers: sem lock; valem o índice único e as novas tentativas.
     */
    private function lockNumbering(string $entityId, string $operatorId, string $prefix): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $key = implode('|', [self::NUMBER_LOCK_NAMESPACE, $entityId, $operatorId, $prefix]);

        // Chave bigint estável (60 bits do sha1, sempre positiva).
        DB::select('select pg_advisory_xact_lock(?)', [(int) hexdec(substr(sha1($key), 0, 15))], false);
    }
}
