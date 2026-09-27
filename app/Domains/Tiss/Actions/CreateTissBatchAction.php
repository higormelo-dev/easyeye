<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorContract};
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, ResolveTissVersionService};
use App\Support\Database\UniqueViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Log};

/**
 * Cria o lote TISS com número LOT-AAAAMM-NNNN (mês de referência), sequencial
 * por clínica × operadora × mês. Mesma proteção de concorrência da guia (ver
 * CreateTissGuideAction): numeração serializada até o commit do chamador,
 * lotes excluídos contados, sequência numérica e nova tentativa limitada.
 */
class CreateTissBatchAction
{
    private const NUMBER_PREFIX = 'LOT';

    /** Largura com zeros à esquerda: dentro dela a ordem de texto é a numérica. */
    private const NUMBER_MIN_DIGITS = 4;

    /** Sequenciais maiores não cabem em int: tratados como fora do padrão. */
    private const NUMBER_MAX_DIGITS = 18;

    /** Tentativas quando o número gerado colide (o lock já evita no fluxo normal). */
    private const MAX_NUMBER_ATTEMPTS = 3;

    private const NUMBER_LOCK_NAMESPACE = 'tiss_batch_number';

    private const NUMBER_UNIQUE_INDEX = 'tiss_batches_entity_operator_batch_unique';

    public function __construct(
        private readonly ResolveTissVersionService $resolveVersionService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * `batch_number` do payload só chega por código interno (nenhum request
     * HTTP o repassa) e nunca é trocado: se colidir, a exceção sobe.
     *
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): TissBatch
    {
        $numberGenerated = ! isset($payload['batch_number']);

        for ($attempt = 1;; $attempt++) {
            try {
                // Transação POR tentativa (SAVEPOINT quando o chamador já abriu uma).
                return DB::transaction(fn (): TissBatch => $this->create($payload));
            } catch (UniqueConstraintViolationException $e) {
                // Pelo nome do índice na mensagem do DRIVER, em qualquer idioma do
                // servidor — não por texto em qualquer lugar da mensagem (SQL/bindings).
                if (
                    ! $numberGenerated
                    || $attempt >= self::MAX_NUMBER_ATTEMPTS
                    || ! UniqueViolation::violates($e, self::NUMBER_UNIQUE_INDEX)
                ) {
                    throw $e;
                }

                Log::warning('tiss.batch.number_collision', [
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
    private function create(array $payload): TissBatch
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

        $referenceMonth = (string) ($payload['reference_month'] ?? now()->format('Y-m'));

        $batch = TissBatch::query()->create([
            'entity_id'       => $entityId,
            'operator_id'     => $payload['operator_id'],
            'contract_id'     => $contract?->id,
            'version_id'      => $version->id,
            'batch_number'    => $payload['batch_number'] ?? $this->nextBatchNumber($entityId, (string) $payload['operator_id'], $referenceMonth),
            'reference_month' => $referenceMonth,
            'status'          => TissBatchStatus::Open->value,
            'guides_count'    => 0,
            'total_amount'    => 0,
            'metadata'        => $payload['metadata'] ?? null,
        ]);

        $this->logStatusTransitionService->record(
            entityId: (string) $batch->entity_id,
            contextType: 'batch',
            contextId: (string) $batch->id,
            currentStatus: (string) $batch->status->value,
            reason: 'Lote TISS criado.',
        );

        return $batch;
    }

    private function nextBatchNumber(string $entityId, string $operatorId, string $referenceMonth): string
    {
        $prefix = sprintf('%s-%s-', self::NUMBER_PREFIX, str_replace('-', '', $referenceMonth));

        $this->lockNumbering($entityId, $operatorId, $prefix);

        // Sem escopos globais: inclui lotes excluídos (soft delete) e ignora o
        // tenant da sessão — exatamente o que o índice único enxerga.
        $numbers = TissBatch::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('operator_id', $operatorId)
            ->where('batch_number', 'like', $prefix . '%');

        $next = $this->lastSequence($numbers, $prefix) + 1;

        return $prefix . str_pad((string) $next, self::NUMBER_MIN_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Caminho normal (O(1), desce pelo índice único): o maior número em ordem
     * de texto tem a largura padrão e não é 9999 => é também o maior
     * numericamente. Senão (largura esgotada ou número fora do padrão no topo)
     * compara numericamente os números do mês, ignorando os fora do padrão.
     *
     * @param Builder<TissBatch> $numbers
     */
    private function lastSequence(Builder $numbers, string $prefix): int
    {
        $column = 'batch_number';
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
     * PostgreSQL: advisory lock de transação por clínica × operadora × mês,
     * até o commit do chamador. Demais drivers: sem lock; valem o índice único
     * e as novas tentativas.
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
