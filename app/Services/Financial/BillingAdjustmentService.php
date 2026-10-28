<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Domains\Tiss\Actions\{DetachGuideFromBatchAction, ResolveTissOperatorForCovenantAction};
use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus};
use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissGuideItem};
use App\Domains\Tiss\PreValidation\TissGuideValidationResult;
use App\Domains\Tiss\Services\{PreValidateTissGuideService, TissWorkflowService};
use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Correções do faturamento fora do fluxo feliz, antes de a guia ir para a
 * operadora: cancelar guia ou lote em RASCUNHO (motivo obrigatório) e
 * "Corrigir pendência" da guia TISS que ficou fora do lote TISS. Extraído de
 * BillingService sem mudar comportamento; as regras (claimCancelError,
 * claimFixPendingError, batchCancelError) são as mesmas que
 * BillingService::allowedClaimActions/allowedBatchActions oferecem à tela.
 *
 * Locks (ordem global em BillingService; helpers em BillingEditLocks):
 * advisory do lote (só tenta) → billing_batches → billing_claims por id →
 * tiss_batches → tiss_guides. Nenhuma numeração, nenhum lock em `schedules`.
 */
class BillingAdjustmentService
{
    /** Guia TISS que já saiu para a operadora (ou voltou dela): não se cancela nem se corrige. */
    public const TISS_GUIDE_SENT_STATUSES = [
        TissGuideStatus::Sent,
        TissGuideStatus::Acknowledged,
        TissGuideStatus::PartiallyDenied,
        TissGuideStatus::Denied,
        TissGuideStatus::Paid,
    ];

    /** Guia TISS fora de lote que ainda aceita "Corrigir pendência" (e entrar num lote TISS). */
    public const TISS_GUIDE_FIXABLE_STATUSES = [
        TissGuideStatus::Draft,
        TissGuideStatus::Authorized,
        TissGuideStatus::Error,
    ];

    public function __construct(
        private readonly TissWorkflowService $tissWorkflow,
        private readonly PreValidateTissGuideService $preValidator,
        private readonly ResolveTissOperatorForCovenantAction $resolveOperator,
        private readonly BillingEditLocks $locks,
    ) {
    }

    /**
     * Cancela uma guia em RASCUNHO, com motivo (gravado na guia; o antes/depois
     * vai para a auditoria). Guia de lote só sai de lote ainda não enviado; a
     * guia TISS vinculada é retirada do lote TISS (se estava nele) e cancelada;
     * o lote tem os totais recalculados e o XML guardado descartado. O
     * atendimento volta para "A faturar": a elegibilidade e o índice único
     * parcial ignoram guia cancelada.
     *
     * @throws ValidationException transição não permitida ou lote em envio (mensagem traduzida)
     */
    public function cancelClaim(BillingClaim $claim, string $reason, ?string $userId = null): BillingClaim
    {
        return DB::transaction(function () use ($claim, $reason, $userId): BillingClaim {
            $entityId         = (string) $claim->entity_id;
            [$batch, $locked] = $this->locks->lockClaimForEdit($entityId, (string) $claim->id);

            $tissBatches = $locked->tiss_guide_id
                ? $this->locks->lockTissBatchesOfGuide($entityId, (string) $locked->tiss_guide_id)
                : collect();
            $guide = $this->locks->lockTissGuides($entityId, [$locked->tiss_guide_id])->first();

            $locked->setRelation('tissGuide', $guide);

            $error = $this->claimCancelError($locked, $tissBatches);

            if ($error !== null) {
                $this->throwClaimError($locked, $error);
            }

            if ($guide !== null) {
                $this->withdrawTissGuide($locked, $guide, $tissBatches);
            }

            $locked->update([
                'status'        => BillingClaimStatus::Cancelled->value,
                'cancel_reason' => $reason,
                'cancelled_by'  => $userId,
                'cancelled_at'  => now(),
            ]);

            if ($batch !== null) {
                $this->refreshBatchTotals($batch);
            }

            return $locked->fresh();
        });
    }

    /**
     * Cancela um lote em RASCUNHO, com motivo: todas as guias em rascunho dele
     * (as mesmas regras de cancelClaim, com o motivo do lote), as guias TISS e
     * o lote TISS; os totais vão a zero. As guias continuam ligadas ao lote
     * (histórico em "Ver guias").
     *
     * @throws ValidationException transição não permitida ou lote em envio (mensagem traduzida)
     */
    public function cancelBatch(BillingBatch $batch, string $reason, ?string $userId = null): BillingBatch
    {
        return DB::transaction(function () use ($batch, $reason, $userId): BillingBatch {
            $entityId = (string) $batch->entity_id;
            $locked   = $this->locks->lockBatch($entityId, (string) $batch->id);

            $error = $this->batchCancelError($locked, null);

            if ($error !== null) {
                $this->throwBatchError($locked, $error);
            }

            $claims = BillingClaim::query()
                ->where('entity_id', $entityId)
                ->where('batch_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $tissBatch = $this->locks->lockTissBatch($entityId, $locked->tiss_batch_id);

            $drafts = $claims->filter(fn (BillingClaim $c): bool => $c->status === BillingClaimStatus::Draft)->values();
            $guides = $this->locks->lockTissGuides($entityId, $drafts->pluck('tiss_guide_id')->all());

            $error = $this->batchCancelError($locked, $tissBatch) ?? match (true) {
                // Rascunho com guia enviada/paga/glosada não acontece pelos fluxos da
                // tela; se acontecer, cancelar o lote apagaria o que já andou.
                $claims->contains(fn (BillingClaim $c): bool => ! in_array($c->status, [BillingClaimStatus::Draft, BillingClaimStatus::Cancelled], true)) => 'batch_cancel_has_settled_claims',
                $guides->contains(fn (TissGuide $g): bool => in_array($g->status, self::TISS_GUIDE_SENT_STATUSES, true))                                  => 'batch_tiss_already_sent',
                default                                                                                                                                   => null,
            };

            if ($error !== null) {
                $this->throwBatchError($locked, $error);
            }

            $history = __('financial_billing.tiss_history.batch_cancelled', ['code' => $locked->code]);

            try {
                foreach ($guides as $guide) {
                    if ($guide->status !== TissGuideStatus::Cancelled) {
                        $this->tissWorkflow->cancelGuide($guide, $history);
                    }
                }

                if ($tissBatch !== null && $tissBatch->status !== TissBatchStatus::Cancelled) {
                    $this->tissWorkflow->cancelBatch($tissBatch, $history);
                }
            } catch (InvalidArgumentException) {
                // Os guards acima já barram o que foi enviado; se o domínio TISS
                // recusar mesmo assim, nada do lote é cancelado (rollback).
                $this->throwBatchError($locked, 'batch_tiss_already_sent');
            }

            $now = now();

            foreach ($drafts as $claim) {
                $claim->update([
                    'status'        => BillingClaimStatus::Cancelled->value,
                    'cancel_reason' => $reason,
                    'cancelled_by'  => $userId,
                    'cancelled_at'  => $now,
                ]);
            }

            $locked->update([
                'status'        => BillingBatchStatus::Cancelled->value,
                'cancel_reason' => $reason,
                'cancelled_by'  => $userId,
                'cancelled_at'  => $now,
            ]);

            $this->refreshBatchTotals($locked);

            return $locked->fresh();
        });
    }

    /**
     * "Corrigir pendência" da guia TISS que ficou fora do lote TISS (erro na
     * pré-validação) ou individual, antes de ir para a operadora: grava CID
     * (indicação clínica), carteirinha e nº da autorização (na guia e nos
     * itens; a autorização também na guia de faturamento), completa o nome do
     * beneficiário com o do paciente se estiver vazio e roda a pré-validação
     * de novo (resultado em tiss_guides.errors, como no lote). Sem erro e com
     * o lote de faturamento ainda em rascunho, a guia entra no lote TISS. Só
     * os campos enviados mudam.
     *
     * @param array{clinical_indication?: string|null, beneficiary_card_number?: string|null, authorization_number?: string|null} $data
     *
     * @return array{claim: BillingClaim, validation: TissGuideValidationResult, attached: bool}
     *
     * @throws ValidationException guia sem pendência corrigível ou lote em envio (mensagem traduzida)
     */
    public function fixPendingGuide(BillingClaim $claim, array $data): array
    {
        return DB::transaction(function () use ($claim, $data): array {
            $entityId         = (string) $claim->entity_id;
            [$batch, $locked] = $this->locks->lockClaimForEdit($entityId, (string) $claim->id);

            $tissBatch = $this->locks->lockTissBatch($entityId, $batch?->tiss_batch_id);
            $guide     = $this->locks->lockTissGuides($entityId, [$locked->tiss_guide_id])->first();

            $locked->setRelation('tissGuide', $guide);

            $error = $this->claimFixPendingError($locked, $guide !== null && $this->locks->guideIsInTissBatch($entityId, (string) $guide->id));

            if ($error !== null) {
                $this->throwClaimError($locked, $error);
            }

            $this->applyGuideFixes($locked, $guide, $data);

            $validation = $this->preValidator->validate($guide->refresh());
            $guide->update(['errors' => $validation->isEmpty() ? null : $validation->toArray()]);

            $attached = ! $validation->hasErrors() && $this->attachFixedGuide($batch, $tissBatch, $locked, $guide);

            return ['claim' => $locked->fresh(), 'validation' => $validation, 'attached' => $attached];
        });
    }

    /**
     * Totais do lote depois de cancelar/incluir/retirar guia: nº de guias
     * ativas (não canceladas) e valor — lote TISS: o do lote TISS (só as guias
     * anexadas, como em createBatch); particular/legado: soma das guias ativas;
     * lote cancelado: zero. O XML guardado ficou velho: o download gera de novo.
     */
    public function refreshBatchTotals(BillingBatch $batch): void
    {
        $active = $batch->claims()->where('status', '!=', BillingClaimStatus::Cancelled->value);

        $amount = match (true) {
            $batch->status === BillingBatchStatus::Cancelled => 0.0,
            (bool) $batch->tiss_batch_id                     => (float) (TissBatch::query()
                ->where('tiss_batches.entity_id', $batch->entity_id)
                ->whereKey($batch->tiss_batch_id)
                ->where('tiss_batches.status', '!=', TissBatchStatus::Cancelled->value)
                ->value('total_amount') ?? 0),
            default => (float) (clone $active)->sum('amount'),
        };

        $batch->update([
            'total_claims' => (clone $active)->count(),
            'total_amount' => round($amount, 2),
            'xml_path'     => null,
        ]);
    }

    /**
     * Regra única de "Cancelar guia" (tela e guard): só rascunho; guia de lote
     * só com o lote ainda em rascunho e fora de lote legado (o XML legado sai
     * com TODAS as guias do lote — lá se cancela o lote inteiro); guia TISS e
     * lote(s) TISS dela ainda não enviados. $tissBatches: lotes TISS em que a
     * guia está — o guard passa os travados; a lista usa o lote TISS do lote
     * quando `tiss_attached`.
     *
     * @param Collection<int, TissBatch>|null $tissBatches
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    public function claimCancelError(BillingClaim $claim, ?Collection $tissBatches = null): ?string
    {
        if ($claim->status === BillingClaimStatus::Cancelled) {
            return 'claim_already_cancelled';
        }

        if ($claim->status !== BillingClaimStatus::Draft) {
            return 'claim_cancel_not_draft';
        }

        $batch = $claim->batch_id ? $claim->batch : null;

        if ($claim->batch_id && ($batch === null || $batch->status !== BillingBatchStatus::Draft)) {
            return 'claim_cancel_batch_not_draft';
        }

        if ($batch !== null && $this->isLegacyTissBatch($batch)) {
            return 'claim_cancel_legacy_batch';
        }

        $guide = $claim->tiss_guide_id ? $claim->tissGuide : null;

        if ($guide !== null && in_array($guide->status, self::TISS_GUIDE_SENT_STATUSES, true)) {
            return 'claim_tiss_already_sent';
        }

        $tissBatches ??= $claim->getAttribute('tiss_attached') && $batch?->tiss_batch_id && $batch->tissBatch
            ? collect([$batch->tissBatch])
            : collect();

        foreach ($tissBatches as $tissBatch) {
            if (! $this->tissBatchAcceptsChanges($tissBatch)) {
                return 'claim_tiss_already_sent';
            }
        }

        return null;
    }

    /**
     * Regra única de "Corrigir pendência" (tela e guard): guia de faturamento
     * TISS em aberto (rascunho/enviada) cuja guia TISS ainda não entrou em
     * lote TISS nem saiu para a operadora — as marcadas "Pendência" ou "Fora
     * de lote" na aba Guias.
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    public function claimFixPendingError(BillingClaim $claim, bool $inTissBatch): ?string
    {
        if (! $claim->tiss_guide_id) {
            return 'fix_pending_not_tiss';
        }

        if ($claim->status === BillingClaimStatus::Cancelled) {
            return 'claim_cancelled';
        }

        $guide = $claim->tissGuide;

        $fixable = in_array($claim->status, [BillingClaimStatus::Draft, BillingClaimStatus::Submitted], true)
            && $guide !== null
            && ! $inTissBatch
            && in_array($guide->status, self::TISS_GUIDE_FIXABLE_STATUSES, true);

        return $fixable ? null : 'fix_pending_not_allowed';
    }

    /**
     * "Cancelar lote" (tela e guard; o guard também confere as guias): só
     * rascunho e com o lote TISS dele ainda não enviado.
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    public function batchCancelError(BillingBatch $batch, ?TissBatch $tissBatch): ?string
    {
        if ($batch->status === BillingBatchStatus::Cancelled) {
            return 'batch_already_cancelled';
        }

        if ($batch->status !== BillingBatchStatus::Draft) {
            return 'batch_cancel_not_draft';
        }

        if ($tissBatch !== null && ! $this->tissBatchAcceptsChanges($tissBatch)) {
            return 'batch_tiss_already_sent';
        }

        return null;
    }

    public function throwClaimError(BillingClaim $claim, string $error): never
    {
        throw ValidationException::withMessages([
            'status' => __("financial_billing.errors.{$error}", [
                'code'   => $claim->code,
                'status' => mb_strtolower($claim->status->label()),
                'batch'  => (string) ($claim->batch_id ? $claim->batch?->code : ''),
            ]),
        ]);
    }

    public function throwBatchError(BillingBatch $batch, string $error): never
    {
        throw ValidationException::withMessages([
            'batch' => __("financial_billing.errors.{$error}", [
                'code'   => $batch->code,
                'status' => mb_strtolower($batch->status->label()),
            ]),
        ]);
    }

    /**
     * Dados corrigidos na guia TISS (só os campos enviados). A autorização vai
     * também para os itens (o XML usa as duas) e para a guia de faturamento.
     *
     * @param array<string, string|null> $data
     */
    private function applyGuideFixes(BillingClaim $claim, TissGuide $guide, array $data): void
    {
        $changes = array_intersect_key($data, array_flip(['clinical_indication', 'beneficiary_card_number', 'authorization_number']));

        if (blank($guide->beneficiary_name) && filled($name = $claim->patient?->person?->full_name)) {
            $changes['beneficiary_name'] = $name;
        }

        if ($changes !== []) {
            $guide->update($changes);
        }

        if (array_key_exists('authorization_number', $changes)) {
            $guide->items()->get()->each(fn (TissGuideItem $item) => $item->update(['authorization_number' => $changes['authorization_number']]));
            $claim->update(['authorization_code' => $changes['authorization_number']]);
        }
    }

    /**
     * Guia corrigida sem erro entra no lote TISS do lote de faturamento — só
     * com o lote ainda em rascunho e o lote TISS não enviado (senão continua
     * fora, como antes).
     */
    private function attachFixedGuide(?BillingBatch $batch, ?TissBatch $tissBatch, BillingClaim $claim, TissGuide $guide): bool
    {
        if (
            $batch === null
            || $tissBatch === null
            || $batch->status !== BillingBatchStatus::Draft
            || $claim->status !== BillingClaimStatus::Draft
            || ! in_array($tissBatch->status, DetachGuideFromBatchAction::EDITABLE_STATUSES, true)
        ) {
            return false;
        }

        try {
            $this->tissWorkflow->attachGuideToBatch($tissBatch, $guide);
        } catch (InvalidArgumentException) {
            // Mesmas regras da pré-validação acima: pendência => fica fora do lote.
            return false;
        }

        $this->refreshBatchTotals($batch);

        return true;
    }

    /**
     * Tira a guia TISS dos lotes TISS ainda não enviados em que está e a
     * cancela (lote TISS já cancelado fica como está — histórico).
     *
     * @param Collection<int, TissBatch> $tissBatches travados (lockTissBatchesOfGuide)
     */
    private function withdrawTissGuide(BillingClaim $claim, TissGuide $guide, Collection $tissBatches): void
    {
        $history = __('financial_billing.tiss_history.claim_cancelled', ['code' => $claim->code]);

        try {
            foreach ($tissBatches as $tissBatch) {
                if ($tissBatch->status !== TissBatchStatus::Cancelled) {
                    $this->tissWorkflow->detachGuideFromBatch($tissBatch, $guide, $history);
                }
            }

            if ($guide->status !== TissGuideStatus::Cancelled) {
                $this->tissWorkflow->cancelGuide($guide, $history);
            }
        } catch (InvalidArgumentException) {
            // Os guards já barram guia/lote TISS enviados; se o domínio TISS
            // recusar mesmo assim, a guia de faturamento não é cancelada (rollback).
            $this->throwClaimError($claim, 'claim_tiss_already_sent');
        }
    }

    /** Lote TISS que ainda aceita tirar/cancelar guia: não enviado (ou já cancelado — só histórico). */
    private function tissBatchAcceptsChanges(TissBatch $tissBatch): bool
    {
        return $tissBatch->status === TissBatchStatus::Cancelled
            || in_array($tissBatch->status, DetachGuideFromBatchAction::EDITABLE_STATUSES, true);
    }

    /**
     * Lote TISS anterior à consolidação com Domains\Tiss: sem lote TISS, XML
     * pelo gerador legado — o convênio é TISS (mesma regra de
     * BillingService::isParticularBatch, negada).
     */
    private function isLegacyTissBatch(BillingBatch $batch): bool
    {
        return ! $batch->tiss_batch_id
            && $batch->covenant !== null
            && $this->resolveOperator->isEligible($batch->covenant);
    }
}
