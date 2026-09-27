<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Domains\Tiss\Actions\DetachGuideFromBatchAction;
use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\{TissBatch, TissGuide};
use App\Domains\Tiss\PreValidation\TissGuideValidationResult;
use App\Domains\Tiss\Services\{PreValidateTissGuideService, TissWorkflowService};
use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim};
use Closure;
use Illuminate\Database\Eloquent\{Builder, ModelNotFoundException};
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Guias TISS que ficaram fora do lote TISS entram num lote de faturamento em
 * RASCUNHO: "Adicionar guias" e "Incluir em lote" (attachClaims) e
 * "Reprocessar pendentes" do lote (reprocessPending).
 *
 * Regras (claimAttachError / batchAttachError, as mesmas que
 * BillingService::allowedClaimActions/allowedBatchActions oferecem à tela):
 *  - guia de faturamento em rascunho, TISS, com a guia TISS em aberto
 *    (rascunho/autorizada/erro) e sem vínculo com lote TISS — individual ou
 *    pendente de um lote em rascunho —, do MESMO convênio do lote e da mesma
 *    operadora do lote TISS;
 *  - lote de faturamento em rascunho, TISS e com o lote TISS ainda não enviado.
 * Guia fora da regra recusa o pedido inteiro (422 com código e motivo de cada
 * uma, nada gravado). Cada guia passa pela pré-validação TISS (resultado em
 * tiss_guides.errors, como no lote); só as sem erro entram no lote TISS
 * (TissWorkflowService::attachGuideToBatch) e passam para o lote de destino
 * (claim.batch_id) — as com erro ficam onde estavam e voltam listadas, sem
 * derrubar as outras. Totais do destino e dos lotes de origem recalculados.
 *
 * Locks (ordem global em BillingService; helpers em BillingEditLocks):
 * advisory dos lotes (só tenta) → billing_batches (destino e origens, por id)
 * → billing_claims por id → tiss_batches → tiss_guides por id. Tudo numa
 * transação; nenhuma numeração, nenhum lock em `schedules`.
 */
class BillingBatchAttachService
{
    /** Teto de guias por requisição (anexar/reprocessar): cada guia roda a pré-validação TISS. */
    public const MAX_CLAIMS = 200;

    /** Teto de lotes de destino listados em "Incluir em lote". */
    public const MAX_TARGETS = 50;

    public function __construct(
        private readonly TissWorkflowService $tissWorkflow,
        private readonly PreValidateTissGuideService $preValidator,
        private readonly BillingEditLocks $locks,
        private readonly BillingAdjustmentService $adjustments,
    ) {
    }

    /**
     * "Adicionar guias" (lote) e "Incluir em lote" (guia).
     *
     * @param array<int, string> $claimIds
     *
     * @return array{batch: BillingBatch, results: list<array{claim: BillingClaim, validation: TissGuideValidationResult, attached: bool}>, remaining: int}
     *
     * @throws ValidationException lote/guia fora da regra ou lote em envio (mensagens traduzidas)
     */
    public function attachClaims(BillingBatch $batch, array $claimIds): array
    {
        $claimIds = array_values(array_unique(array_map(fn ($id): string => mb_strtolower((string) $id), $claimIds)));

        if ($claimIds === [] || count($claimIds) > self::MAX_CLAIMS) {
            throw ValidationException::withMessages([
                'claim_ids' => __('financial_billing.validation.claims_max', ['max' => self::MAX_CLAIMS]),
            ]);
        }

        return DB::transaction(function () use ($batch, $claimIds): array {
            $entityId = (string) $batch->entity_id;

            // Lotes de origem (guia pendente de outro lote): lidos sem lock só
            // para travá-los junto com o destino no passo 1 (por id).
            $sources = BillingClaim::query()
                ->where('entity_id', $entityId)
                ->whereIn('id', $claimIds)
                ->whereNotNull('batch_id')
                ->distinct()
                ->pluck('batch_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            $batches = $this->locks->lockBatches($entityId, [(string) $batch->id, ...$sources]);
            $target  = $batches->get((string) $batch->id) ?? throw (new ModelNotFoundException())->setModel(BillingBatch::class, [$batch->id]);

            $this->guardTargetState($target);

            $claims = $this->locks->lockClaims($entityId, $claimIds);

            if ($claims->count() !== count($claimIds)) {
                throw ValidationException::withMessages([
                    'claim_ids' => __('financial_billing.errors.attach_claims_not_found'),
                ]);
            }

            foreach ($claims as $claim) {
                // Mudou de lote entre a leitura e o lock: o lote novo não está travado.
                if ($claim->batch_id !== null && ! $batches->has((string) $claim->batch_id)) {
                    $this->locks->throwClaimBusy($claim);
                }

                $claim->setRelation('batch', $claim->batch_id === null ? null : $batches->get((string) $claim->batch_id));
            }

            $tissBatch = $this->lockTargetTissBatch($target);
            $this->lockGuidesAndGuard($target, $tissBatch, $claims);

            return $this->attachLocked($target, $tissBatch, $claims, $batches) + ['remaining' => 0];
        });
    }

    /**
     * "Reprocessar pendentes": refaz a pré-validação das guias do lote que
     * ficaram fora do lote TISS e anexa as que passaram. Até MAX_CLAIMS por
     * vez (por id); `remaining` = pendências não processadas agora.
     *
     * @return array{batch: BillingBatch, results: list<array{claim: BillingClaim, validation: TissGuideValidationResult, attached: bool}>, remaining: int}
     *
     * @throws ValidationException lote fora da regra, sem pendência ou em envio (mensagens traduzidas)
     */
    public function reprocessPending(BillingBatch $batch): array
    {
        return DB::transaction(function () use ($batch): array {
            $entityId = (string) $batch->entity_id;
            $target   = $this->locks->lockBatch($entityId, (string) $batch->id);

            $this->guardTargetState($target);

            $total  = $this->pendingClaimsQuery($target)->count();
            $claims = $this->pendingClaimsQuery($target)
                ->orderBy('billing_claims.id')
                ->limit(self::MAX_CLAIMS)
                ->lockForUpdate()
                ->get();

            if ($claims->isEmpty()) {
                $this->adjustments->throwBatchError($target, 'reprocess_nothing_pending');
            }

            $claims->each(fn (BillingClaim $claim) => $claim->setRelation('batch', $target));

            $tissBatch = $this->lockTargetTissBatch($target);
            $this->lockGuidesAndGuard($target, $tissBatch, $claims);

            $result = $this->attachLocked($target, $tissBatch, $claims, collect([(string) $target->id => $target]));

            return $result + ['remaining' => max(0, $total - $claims->count())];
        });
    }

    /**
     * "Incluir em lote" para a guia da lista (BillingService::allowedClaimActions):
     * a regra da guia e ao menos um lote de destino do convênio — senão a ação
     * seria um beco sem saída. $attachTargets: covenantsWithAttachTarget() da
     * página inteira; null consulta só o convênio desta guia.
     *
     * @param array<string, true>|null $attachTargets
     *
     * @return list<string>
     */
    public function allowedClaimActions(BillingClaim $claim, ?array $attachTargets = null): array
    {
        if ($this->claimAttachError($claim, (bool) $claim->getAttribute('tiss_attached')) !== null) {
            return [];
        }

        $attachTargets ??= $this->covenantsWithAttachTarget((string) $claim->entity_id, [$claim->covenant_id]);

        return isset($attachTargets[(string) $claim->covenant_id]) ? [BillingService::CLAIM_ACTION_ATTACH] : [];
    }

    /**
     * "Adicionar guias" e, com pendência no lote, "Reprocessar pendentes"
     * (BillingService::allowedBatchActions).
     *
     * @return list<string>
     */
    public function allowedBatchActions(BillingBatch $batch, ?TissBatch $tissBatch): array
    {
        if ($this->batchAttachError($batch, $tissBatch) !== null) {
            return [];
        }

        return $this->pendingCount($batch) > 0
            ? [BillingService::BATCH_ACTION_ADD_CLAIMS, BillingService::BATCH_ACTION_REPROCESS]
            : [BillingService::BATCH_ACTION_ADD_CLAIMS];
    }

    /**
     * Regra de "Incluir em lote"/"Adicionar guias" para a guia (tela e
     * guard), sem o lote de destino (conferido à parte). Usa as relações
     * `batch` e `tissGuide` já carregadas.
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    public function claimAttachError(BillingClaim $claim, bool $inTissBatch): ?string
    {
        $guide = $claim->tiss_guide_id ? $claim->tissGuide : null;
        $batch = $claim->batch_id ? $claim->batch : null;

        return match (true) {
            $claim->status === BillingClaimStatus::Cancelled => 'claim_cancelled',
            ! $claim->tiss_guide_id                          => 'attach_claim_not_tiss',
            $claim->status !== BillingClaimStatus::Draft     => 'attach_claim_not_draft',
            $inTissBatch                                     => 'attach_claim_in_tiss_batch',
            $guide === null
                || ! in_array($guide->status, BillingAdjustmentService::TISS_GUIDE_FIXABLE_STATUSES, true) => 'attach_claim_guide_locked',
            $claim->batch_id !== null
                && ($batch === null || $batch->status !== BillingBatchStatus::Draft || ! $batch->tiss_batch_id) => 'attach_claim_batch_locked',
            default                                                                                             => null,
        };
    }

    /** claimAttachError consultando o vínculo com lote TISS (tela de uma guia só). */
    public function claimAttachErrorFor(BillingClaim $claim): ?string
    {
        $inTissBatch = $claim->tiss_guide_id !== null
            && $this->locks->guideIsInTissBatch((string) $claim->entity_id, (string) $claim->tiss_guide_id);

        return $this->claimAttachError($claim, $inTissBatch);
    }

    /**
     * Regra do lote de destino (tela e guard): lote de faturamento em
     * rascunho, TISS (particular e lote antigo não têm lote TISS onde anexar)
     * e com o lote TISS ainda não enviado.
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    public function batchAttachError(BillingBatch $batch, ?TissBatch $tissBatch): ?string
    {
        return $this->batchStateError($batch) ?? match (true) {
            $tissBatch === null || $tissBatch->status === TissBatchStatus::Cancelled            => 'attach_batch_not_tiss',
            ! in_array($tissBatch->status, DetachGuideFromBatchAction::EDITABLE_STATUSES, true) => 'attach_batch_tiss_sent',
            default                                                                             => null,
        };
    }

    /**
     * Guias do lote que ficaram fora do lote TISS (para "Reprocessar
     * pendentes"): da lista (`claims_count` do withCount e o lote TISS já
     * carregados) ou, sem eles, uma consulta.
     */
    public function pendingCount(BillingBatch $batch): int
    {
        if (! $batch->tiss_batch_id) {
            return 0;
        }

        $claims = $batch->getAttribute('claims_count');

        if ($claims === null) {
            return $this->pendingClaimsQuery($batch)->count();
        }

        return max(0, (int) $claims - (int) ($batch->tissBatch?->guides_count ?? 0));
    }

    /**
     * Guias que podem entrar no lote (modal "Adicionar guias"), sem lock:
     * a regra de claimAttachError, do convênio do lote e (quando informada) da
     * operadora do lote TISS — individuais, pendentes deste ou de outro lote
     * em rascunho.
     *
     * @return Builder<BillingClaim>
     */
    public function attachableClaimsQuery(BillingBatch $batch, ?string $operatorId): Builder
    {
        $entityId = (string) $batch->entity_id;

        return BillingClaim::query()
            ->where('billing_claims.entity_id', $entityId)
            ->where('billing_claims.covenant_id', $batch->covenant_id)
            ->where('billing_claims.status', BillingClaimStatus::Draft->value)
            ->whereNotNull('billing_claims.tiss_guide_id')
            ->whereNotExists($this->activeTissLink())
            ->whereExists(fn (QueryBuilder $guide) => $guide->selectRaw('1')
                ->from('tiss_guides')
                ->whereColumn('tiss_guides.id', 'billing_claims.tiss_guide_id')
                ->where('tiss_guides.entity_id', $entityId)
                ->whereNull('tiss_guides.deleted_at')
                ->whereIn('tiss_guides.status', array_map(fn ($status) => $status->value, BillingAdjustmentService::TISS_GUIDE_FIXABLE_STATUSES))
                ->when($operatorId !== null, fn (QueryBuilder $q) => $q->where('tiss_guides.operator_id', $operatorId)))
            ->where(fn (Builder $origin) => $origin
                ->whereNull('billing_claims.batch_id')
                ->orWhereExists(fn (QueryBuilder $source) => $source->selectRaw('1')
                    ->from('billing_batches')
                    ->whereColumn('billing_batches.id', 'billing_claims.batch_id')
                    ->where('billing_batches.entity_id', $entityId)
                    ->where('billing_batches.status', BillingBatchStatus::Draft->value)
                    ->whereNotNull('billing_batches.tiss_batch_id')
                    ->whereNull('billing_batches.deleted_at')));
    }

    /**
     * Lotes que podem receber guia de um convênio ("Incluir em lote"): em
     * rascunho, TISS e com o lote TISS não enviado (da operadora, se informada).
     *
     * @return Builder<BillingBatch>
     */
    public function attachTargetsQuery(string $entityId, ?string $covenantId, ?string $operatorId = null): Builder
    {
        return $this->targetsQuery($entityId, $operatorId)
            ->when($covenantId === null, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($covenantId !== null, fn (Builder $q) => $q->where('billing_batches.covenant_id', $covenantId));
    }

    /**
     * Convênios (dos informados) com ao menos um lote que recebe guia — a
     * tela só oferece "Incluir em lote" quando há destino (uma consulta por página).
     *
     * @param array<int, string|null> $covenantIds
     *
     * @return array<string, true>
     */
    public function covenantsWithAttachTarget(string $entityId, array $covenantIds): array
    {
        $covenantIds = array_values(array_unique(array_filter(array_map('strval', $covenantIds))));

        if ($covenantIds === []) {
            return [];
        }

        return $this->targetsQuery($entityId, null)
            ->whereIn('billing_batches.covenant_id', $covenantIds)
            ->distinct()
            ->pluck('billing_batches.covenant_id')
            ->mapWithKeys(fn ($id): array => [(string) $id => true])
            ->all();
    }

    /** Passo 1 do guard: lote de faturamento (já travado) em rascunho e TISS. */
    private function guardTargetState(BillingBatch $target): void
    {
        $error = $this->batchStateError($target);

        if ($error !== null) {
            $this->adjustments->throwBatchError($target, $error);
        }
    }

    /** Passo 3: o lote TISS do destino, travado e conferido (não enviado). */
    private function lockTargetTissBatch(BillingBatch $target): TissBatch
    {
        $tissBatch = $this->locks->lockTissBatch((string) $target->entity_id, $target->tiss_batch_id);
        $error     = $this->batchAttachError($target, $tissBatch);

        if ($error !== null) {
            $this->adjustments->throwBatchError($target, $error);
        }

        return $tissBatch;
    }

    /**
     * Último passo (guias TISS por id) e a regra por guia: qualquer guia fora
     * da regra recusa o pedido inteiro, com o código e o motivo de cada uma.
     *
     * @param Collection<int, BillingClaim> $claims travadas
     */
    private function lockGuidesAndGuard(BillingBatch $target, TissBatch $tissBatch, Collection $claims): void
    {
        $entityId = (string) $target->entity_id;
        $guides   = $this->locks->lockTissGuides($entityId, $claims->pluck('tiss_guide_id')->all())
            ->keyBy(fn (TissGuide $guide): string => (string) $guide->id);
        $inTiss = $this->locks->guidesInTissBatches($entityId, $guides->keys()->all());

        $failures = [];

        foreach ($claims as $claim) {
            $guideId = (string) $claim->tiss_guide_id;
            $claim->setRelation('tissGuide', $guides->get($guideId));

            $error = $this->claimAttachError($claim, isset($inTiss[$guideId])) ?? match (true) {
                (string) $claim->covenant_id !== (string) $target->covenant_id                => 'attach_other_covenant',
                (string) $claim->tissGuide?->operator_id !== (string) $tissBatch->operator_id => 'attach_other_operator',
                default                                                                       => null,
            };

            if ($error !== null) {
                $failures["claims.{$claim->id}"] = __("financial_billing.errors.{$error}", [
                    'code'   => $claim->code,
                    'status' => mb_strtolower($claim->status->label()),
                    'batch'  => (string) ($claim->batch?->code ?? ''),
                    'target' => $target->code,
                ]);
            }
        }

        if ($failures !== []) {
            throw ValidationException::withMessages($failures);
        }
    }

    /**
     * Pré-validação por guia (gravada em tiss_guides.errors); sem erro a guia
     * entra no lote TISS e a guia de faturamento passa para o lote de destino;
     * com erro fica onde estava. Depois: período e totais do destino e totais
     * dos lotes de origem que perderam guia.
     *
     * @param Collection<int, BillingClaim>    $claims  travadas, com tissGuide travada
     * @param Collection<string, BillingBatch> $batches lotes travados (destino e origens), por id
     *
     * @return array{batch: BillingBatch, results: list<array{claim: BillingClaim, validation: TissGuideValidationResult, attached: bool}>}
     */
    private function attachLocked(BillingBatch $target, TissBatch $tissBatch, Collection $claims, Collection $batches): array
    {
        $results = [];
        $sources = [];

        foreach ($claims as $claim) {
            $guide      = $claim->tissGuide;
            $validation = $this->preValidator->validate($guide);
            $guide->update(['errors' => $validation->isEmpty() ? null : $validation->toArray()]);

            $attached = ! $validation->hasErrors() && $this->attachGuide($tissBatch, $guide);

            if ($attached && (string) $claim->batch_id !== (string) $target->id) {
                if ($claim->batch_id !== null) {
                    $sources[(string) $claim->batch_id] = true;
                }

                $claim->update(['batch_id' => $target->id]);
                $claim->setRelation('batch', $target);
            }

            $results[] = ['claim' => $claim, 'validation' => $validation, 'attached' => $attached];
        }

        $this->coverAttendanceDates($target, $results);
        $this->adjustments->refreshBatchTotals($target);

        foreach (array_keys($sources) as $sourceId) {
            $this->adjustments->refreshBatchTotals($batches->get($sourceId));
        }

        return ['batch' => $target->fresh(), 'results' => $results];
    }

    private function attachGuide(TissBatch $tissBatch, TissGuide $guide): bool
    {
        try {
            $this->tissWorkflow->attachGuideToBatch($tissBatch, $guide);
        } catch (InvalidArgumentException) {
            // Mesmas regras da pré-validação acima: pendência => fica fora do lote.
            return false;
        }

        return true;
    }

    /**
     * O período do lote (atendimentos) passa a cobrir as guias que entraram —
     * senão o lote some do filtro de período das guias que ele contém.
     *
     * @param list<array{claim: BillingClaim, attached: bool}> $results
     */
    private function coverAttendanceDates(BillingBatch $target, array $results): void
    {
        $dates = collect($results)
            ->filter(fn (array $result): bool => $result['attached'] && $result['claim']->attendance_date !== null)
            ->map(fn (array $result) => $result['claim']->attendance_date);

        if ($dates->isEmpty()) {
            return;
        }

        $changes = [];

        if ($target->period_start === null || $dates->min()->lt($target->period_start)) {
            $changes['period_start'] = $dates->min()->toDateString();
        }

        if ($target->period_end === null || $dates->max()->gt($target->period_end)) {
            $changes['period_end'] = $dates->max()->toDateString();
        }

        if ($changes !== []) {
            $target->update($changes);
        }
    }

    /** @return string|null lote de faturamento que não recebe guia (sem olhar o lote TISS) */
    private function batchStateError(BillingBatch $batch): ?string
    {
        return match (true) {
            $batch->status !== BillingBatchStatus::Draft => 'attach_batch_not_draft',
            ! $batch->tiss_batch_id                      => 'attach_batch_not_tiss',
            default                                      => null,
        };
    }

    /**
     * Guias do lote em rascunho, TISS, fora do lote TISS.
     *
     * @return Builder<BillingClaim>
     */
    private function pendingClaimsQuery(BillingBatch $batch): Builder
    {
        return BillingClaim::query()
            ->where('billing_claims.entity_id', $batch->entity_id)
            ->where('billing_claims.batch_id', $batch->id)
            ->where('billing_claims.status', BillingClaimStatus::Draft->value)
            ->whereNotNull('billing_claims.tiss_guide_id')
            ->whereNotExists($this->activeTissLink());
    }

    /**
     * Lotes em rascunho, TISS, com o lote TISS não enviado.
     *
     * @return Builder<BillingBatch>
     */
    private function targetsQuery(string $entityId, ?string $operatorId): Builder
    {
        return BillingBatch::query()
            ->where('billing_batches.entity_id', $entityId)
            ->where('billing_batches.status', BillingBatchStatus::Draft->value)
            ->whereNotNull('billing_batches.tiss_batch_id')
            ->whereExists(fn (QueryBuilder $tiss) => $tiss->selectRaw('1')
                ->from('tiss_batches')
                ->whereColumn('tiss_batches.id', 'billing_batches.tiss_batch_id')
                ->where('tiss_batches.entity_id', $entityId)
                ->whereNull('tiss_batches.deleted_at')
                ->whereIn('tiss_batches.status', array_map(fn ($status) => $status->value, DetachGuideFromBatchAction::EDITABLE_STATUSES))
                ->when($operatorId !== null, fn (QueryBuilder $q) => $q->where('tiss_batches.operator_id', $operatorId)));
    }

    /** NOT EXISTS: a guia TISS da linha de billing_claims tem vínculo ativo com lote TISS. */
    private function activeTissLink(): Closure
    {
        return fn (QueryBuilder $link) => $link->selectRaw('1')
            ->from('tiss_batch_guides')
            ->whereColumn('tiss_batch_guides.guide_id', 'billing_claims.tiss_guide_id')
            ->whereColumn('tiss_batch_guides.entity_id', 'billing_claims.entity_id')
            ->whereNull('tiss_batch_guides.deleted_at');
    }
}
