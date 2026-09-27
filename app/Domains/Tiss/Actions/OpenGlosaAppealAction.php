<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal};
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use App\Exceptions\Financial\{AppealNumberUnavailableException, GlosaNotAppealableException};
use App\Models\Entity;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Log};

/**
 * Abre o recurso de uma glosa com número REC-AAAAMM-NNNNN, sequencial por
 * clínica (entity_id) e mês.
 *
 * Concorrência (antes: 2 aberturas simultâneas na mesma clínica liam o mesmo
 * "último número" e a segunda estourava o índice único => HTTP 500):
 *  1. a glosa é relida com lock e rechecada — a mesma glosa não recebe 2 recursos;
 *  2. a numeração é serializada por clínica dentro da transação;
 *  3. o próximo número é calculado NUMERICAMENTE (99999 < 100000) e conta
 *     recursos excluídos (soft delete), que o índice único continua vendo;
 *  4. colisão residual no índice (escritor fora do lock, ex.: instância antiga
 *     durante deploy) => nova tentativa limitada; esgotada => exceção de domínio.
 */
class OpenGlosaAppealAction
{
    private const NUMBER_PREFIX = 'REC';

    /** Zeros à esquerda do sequencial (acima de 99999 o número só cresce). */
    private const NUMBER_MIN_DIGITS = 5;

    /** Sequenciais com mais dígitos que isso não cabem em int: tratados como fora do padrão. */
    private const NUMBER_MAX_DIGITS = 18;

    /** Tentativas quando o número colide no índice único (o lock já evita no fluxo normal). */
    private const MAX_NUMBER_ATTEMPTS = 3;

    /** Namespace da chave do advisory lock da numeração (PostgreSQL). */
    private const NUMBER_LOCK_NAMESPACE = 'tiss_glosa_appeal_number';

    /** Índice único da numeração (migration 2026_04_04_120400). */
    private const NUMBER_UNIQUE_INDEX = 'tiss_glosa_appeals_entity_number_unique';

    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * `appeal_number` do payload é IGNORADO: o número é sempre do sistema
     * (valor externo podia colidir no índice único e virar 500).
     *
     * @param array<string, mixed> $payload reason, requested_amount, protocol_id, xml_document_id, metadata
     *
     * @throws GlosaNotAppealableException      glosa não está mais recorrível (relida sob lock)
     * @throws AppealNumberUnavailableException número segue colidindo após as tentativas
     */
    public function __invoke(TissGlosa $glosa, array $payload = []): TissGlosaAppeal
    {
        for ($attempt = 1;; $attempt++) {
            try {
                // Transação POR tentativa: no PostgreSQL um erro aborta a transação
                // (ou o savepoint, se o chamador já abriu uma) — a nova tentativa
                // precisa começar limpa e reler o último número.
                return DB::transaction(fn (): TissGlosaAppeal => $this->open($glosa, $payload));
            } catch (UniqueConstraintViolationException $e) {
                if (! $this->isAppealNumberCollision($e)) {
                    throw $e;
                }

                Log::warning('tiss.glosa_appeal.number_collision', [
                    'entity_id' => (string) $glosa->entity_id,
                    'glosa_id'  => (string) $glosa->id,
                    'attempt'   => $attempt,
                ]);

                if ($attempt >= self::MAX_NUMBER_ATTEMPTS) {
                    throw new AppealNumberUnavailableException(
                        sprintf('Appeal number still colliding after %d attempts.', $attempt),
                        0,
                        $e,
                    );
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function open(TissGlosa $glosa, array $payload): TissGlosaAppeal
    {
        // Relê com lock: a instância recebida pode estar desatualizada (duas
        // abas/usuários). Ordem de locks fixa (glosa -> numeração) evita deadlock.
        $locked = TissGlosa::query()
            ->whereKey($glosa->getKey())
            ->where('entity_id', $glosa->entity_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->status->isActionable()) {
            throw new GlosaNotAppealableException(
                sprintf('Glosa %s is not appealable in status "%s".', $locked->id, $locked->status->value),
            );
        }

        $entityId = (string) $locked->entity_id;

        $this->lockNumbering($entityId);

        $appeal = TissGlosaAppeal::query()->create([
            'entity_id'        => $entityId,
            'glosa_id'         => $locked->id,
            'protocol_id'      => $payload['protocol_id'] ?? null,
            'xml_document_id'  => $payload['xml_document_id'] ?? null,
            'appeal_number'    => $this->nextAppealNumber($entityId),
            'status'           => TissAppealStatus::Opened->value,
            'reason'           => $payload['reason'] ?? null,
            'requested_amount' => $payload['requested_amount'] ?? $locked->amount,
            'accepted_amount'  => 0,
            'metadata'         => $payload['metadata'] ?? null,
        ]);

        $locked->update(['status' => TissGlosaStatus::Appealed->value]);

        $this->logStatusTransitionService->record(
            entityId: $entityId,
            contextType: 'glosa',
            contextId: (string) $locked->id,
            currentStatus: TissGlosaStatus::Appealed->value,
            previousStatus: TissGlosaStatus::Open->value,
            reason: sprintf('Recurso de glosa %s aberto.', $appeal->appeal_number),
            payload: ['appeal_id' => (string) $appeal->id],
        );

        return $appeal->setRelation('glosa', $locked);
    }

    /**
     * Serializa a numeração da clínica até o fim da transação.
     *
     * PostgreSQL: advisory lock de transação — só quem numera recurso nesta
     * clínica espera; a linha de `entities` fica livre (um FOR UPDATE nela
     * bloquearia todo INSERT com FK para a clínica durante a transação).
     * Demais drivers: lock na linha da entidade (mesmo padrão do CashClosingService).
     */
    private function lockNumbering(string $entityId): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(?)', [$this->numberLockKey($entityId)]);

            return;
        }

        Entity::query()->whereKey($entityId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Chave bigint estável por clínica (60 bits do sha1, sempre positiva).
     * Colisão entre clínicas só serializaria as duas — nunca mistura números.
     */
    private function numberLockKey(string $entityId): int
    {
        return (int) hexdec(substr(sha1(self::NUMBER_LOCK_NAMESPACE . '|' . $entityId), 0, 15));
    }

    /**
     * Maior sequencial do mês + 1, comparado como NÚMERO. Considera recursos
     * excluídos (soft delete) e ignora escopos globais para enxergar exatamente
     * o que o índice único (entity_id, appeal_number) enxerga. Números fora do
     * padrão (legado/importado) são ignorados. Custo: O(recursos da clínica no mês).
     */
    private function nextAppealNumber(string $entityId): string
    {
        $prefix  = sprintf('%s-%s-', self::NUMBER_PREFIX, now()->format('Ym'));
        $pattern = sprintf('/^%s(\d{1,%d})$/', preg_quote($prefix, '/'), self::NUMBER_MAX_DIGITS);

        $last = TissGlosaAppeal::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('appeal_number', 'like', $prefix . '%')
            ->pluck('appeal_number')
            ->map(fn (string $number): int => preg_match($pattern, $number, $match) === 1 ? (int) $match[1] : 0)
            ->max() ?? 0;

        return $prefix . str_pad((string) ($last + 1), self::NUMBER_MIN_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * A violação é do índice (entity_id, appeal_number)? Pelo nome do índice na
     * mensagem do driver, em qualquer idioma (UniqueViolation) — antes decidia
     * pelo texto do SQL, e qualquer violação no INSERT do recurso (ex.: PK)
     * virava "número indisponível" após 3 tentativas, escondendo o erro real.
     * Violações de outras constraints/tabelas (auditoria, histórico) sobem.
     */
    private function isAppealNumberCollision(UniqueConstraintViolationException $e): bool
    {
        return UniqueViolation::violates($e, self::NUMBER_UNIQUE_INDEX)
            || UniqueViolation::violatesColumns($e, (new TissGlosaAppeal())->getTable(), 'entity_id', 'appeal_number');
    }
}
