<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Domains\Tiss\Models\{TissBatch, TissBatchGuide, TissGuide};
use App\Models\{BillingBatch, BillingClaim};
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Locks de EDIÇÃO do faturamento — cancelar/corrigir guia ou lote
 * (BillingAdjustmentService), anexar guias a um lote (BillingBatchAttachService)
 * e o advisory de sessão do envio (BillingService::submitBatch) —, sempre na
 * ordem global documentada em BillingService, sem voltar atrás:
 *  0. advisory do lote (chave billing_batch_edit|id): o envio segura em SESSÃO;
 *     quem edita só TENTA, em transação (nunca espera) → "lote em envio";
 *  1. billing_batches FOR UPDATE (por id);
 *  2. billing_claims FOR UPDATE (por id);
 *  3. tiss_batches FOR UPDATE (por id) e, por último, tiss_guides FOR UPDATE (por id).
 *
 * Extraído de BillingService com a mesma SQL (BillingCancelLockTest confere a
 * ordem dos comandos). Toda consulta é escopada pela clínica.
 */
class BillingEditLocks
{
    /** Namespace da chave do advisory (não colide com a numeração nem com o caixa). */
    private const NAMESPACE = 'billing_batch_edit';

    /** Passos 0 e 1: advisory de edição do lote (só TENTA) e a linha dele. */
    public function lockBatch(string $entityId, string $batchId): BillingBatch
    {
        if (! $this->tryLock($batchId, session: false)) {
            $this->throwBusy((string) BillingBatch::query()->where('entity_id', $entityId)->whereKey($batchId)->value('code'));
        }

        return BillingBatch::query()->where('entity_id', $entityId)->whereKey($batchId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Passos 0 e 1 para vários lotes (levar guia de um lote para outro): tenta
     * o advisory de cada um e trava as linhas numa consulta só, por id — dois
     * pedidos que mexem nos mesmos lotes pegam os locks na mesma ordem.
     *
     * @param array<int, string|null> $batchIds
     *
     * @return Collection<string, BillingBatch> por id (lote de outra clínica ou excluído fica de fora)
     */
    public function lockBatches(string $entityId, array $batchIds): Collection
    {
        $batchIds = $this->ids($batchIds);

        foreach ($batchIds as $batchId) {
            if (! $this->tryLock($batchId, session: false)) {
                $this->throwBusy((string) BillingBatch::query()->where('entity_id', $entityId)->whereKey($batchId)->value('code'));
            }
        }

        if ($batchIds === []) {
            return collect();
        }

        return BillingBatch::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', $batchIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (BillingBatch $batch): string => (string) $batch->id)
            ->toBase();
    }

    /**
     * Passos 0 a 2 para mexer numa guia: advisory + linha do lote dela (se
     * houver) e a linha da guia.
     *
     * @return array{0: BillingBatch|null, 1: BillingClaim}
     */
    public function lockClaimForEdit(string $entityId, string $claimId): array
    {
        $batchId = BillingClaim::query()->where('entity_id', $entityId)->whereKey($claimId)->value('batch_id');
        $batch   = $batchId === null ? null : $this->lockBatch($entityId, (string) $batchId);

        $claim = BillingClaim::query()->where('entity_id', $entityId)->whereKey($claimId)->lockForUpdate()->firstOrFail();

        // O lote de uma guia não muda enquanto ela está travada; se mudou
        // enquanto esperava o lock, recusa em vez de seguir com o lote errado.
        if ((string) $claim->batch_id !== (string) $batchId) {
            $this->throwClaimBusy($claim);
        }

        $claim->setRelation('batch', $batch);

        return [$batch, $claim];
    }

    /**
     * Passo 2: guias travadas por id crescente, numa consulta (escopada pela clínica).
     *
     * @param array<int, string|null> $claimIds
     *
     * @return Collection<int, BillingClaim>
     */
    public function lockClaims(string $entityId, array $claimIds): Collection
    {
        $claimIds = $this->ids($claimIds);

        if ($claimIds === []) {
            return collect();
        }

        return BillingClaim::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', $claimIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Passo 3: o lote TISS (se houver). */
    public function lockTissBatch(string $entityId, ?string $tissBatchId): ?TissBatch
    {
        if (blank($tissBatchId)) {
            return null;
        }

        return TissBatch::query()->where('tiss_batches.entity_id', $entityId)->whereKey($tissBatchId)->lockForUpdate()->first();
    }

    /**
     * Passo 3: lotes TISS (vínculo ativo) em que a guia está, travados por id.
     *
     * @return Collection<int, TissBatch>
     */
    public function lockTissBatchesOfGuide(string $entityId, string $guideId): Collection
    {
        $ids = TissBatchGuide::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('guide_id', $guideId)
            ->whereNull('deleted_at')
            ->pluck('batch_id')
            ->all();

        if ($ids === []) {
            return collect();
        }

        return TissBatch::query()
            ->where('tiss_batches.entity_id', $entityId)
            ->whereIn('tiss_batches.id', $ids)
            ->orderBy('tiss_batches.id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Último passo: guias TISS travadas por id.
     *
     * @param array<int, string|null> $guideIds
     *
     * @return Collection<int, TissGuide>
     */
    public function lockTissGuides(string $entityId, array $guideIds): Collection
    {
        $guideIds = array_values(array_unique(array_filter(array_map('strval', $guideIds))));

        if ($guideIds === []) {
            return collect();
        }

        return TissGuide::query()
            ->where('tiss_guides.entity_id', $entityId)
            ->whereIn('tiss_guides.id', $guideIds)
            ->orderBy('tiss_guides.id')
            ->lockForUpdate()
            ->get();
    }

    /** A guia TISS tem vínculo ativo com algum lote TISS? */
    public function guideIsInTissBatch(string $entityId, string $guideId): bool
    {
        return TissBatchGuide::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('guide_id', $guideId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Quais destas guias TISS têm vínculo ativo com algum lote TISS (uma consulta).
     *
     * @param array<int, string|null> $guideIds
     *
     * @return array<string, true>
     */
    public function guidesInTissBatches(string $entityId, array $guideIds): array
    {
        $guideIds = $this->ids($guideIds);

        if ($guideIds === []) {
            return [];
        }

        return TissBatchGuide::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereIn('guide_id', $guideIds)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('guide_id')
            ->mapWithKeys(fn ($id): array => [(string) $id => true])
            ->all();
    }

    /**
     * Envio sob o advisory de edição do lote em nível de SESSÃO (atravessa as
     * transações curtas e o I/O da fase TISS): cancelar/corrigir/anexar guia
     * deste lote só tenta o mesmo lock e recusa enquanto o envio não termina. O
     * envio também só tenta: outro envio do mesmo lote (ou uma edição em curso)
     * recusa na hora, sem ficar esperando o I/O do outro.
     *
     * @template T
     *
     * @param Closure(): T $submit
     *
     * @return T
     */
    public function whileSubmitting(BillingBatch $batch, Closure $submit): mixed
    {
        if (! $this->tryLock((string) $batch->id, session: true)) {
            $this->throwBusy($batch->code);
        }

        try {
            return $submit();
        } finally {
            $this->releaseSessionLock((string) $batch->id);
        }
    }

    /** Chave bigint estável por lote (60 bits do sha1, sempre positiva — mesmo esquema da numeração). */
    public function key(string $batchId): int
    {
        return (int) hexdec(substr(sha1(self::NAMESPACE . '|' . $batchId), 0, 15));
    }

    public function throwBusy(string $code): never
    {
        throw ValidationException::withMessages([
            'batch' => __('financial_billing.errors.batch_busy', ['code' => $code]),
        ]);
    }

    public function throwClaimBusy(BillingClaim $claim): never
    {
        throw ValidationException::withMessages([
            'status' => __('financial_billing.errors.claim_busy', ['code' => $claim->code]),
        ]);
    }

    /**
     * pg_try_advisory_lock (sessão) ou pg_try_advisory_xact_lock (até o
     * commit) na chave do lote — nunca espera, então não entra em ciclo de
     * espera com os locks de linha. Fora do PostgreSQL: sem lock (true).
     */
    private function tryLock(string $batchId, bool $session): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return true;
        }

        $sql = $session
            ? 'select pg_try_advisory_lock(?) as acquired'
            : 'select pg_try_advisory_xact_lock(?) as acquired';

        return (bool) (DB::selectOne($sql, [$this->key($batchId)])?->acquired ?? false);
    }

    private function releaseSessionLock(string $batchId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::select('select pg_advisory_unlock(?)', [$this->key($batchId)]);
        } catch (Throwable $e) {
            // Só falha com a conexão numa transação abortada; o lock de sessão
            // cai junto com a sessão. Registra sem mascarar a exceção original.
            report($e);
        }
    }

    /**
     * Ids únicos, sem vazios, em ordem crescente (a ordem dos locks).
     *
     * @param array<int, string|null> $ids
     *
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
