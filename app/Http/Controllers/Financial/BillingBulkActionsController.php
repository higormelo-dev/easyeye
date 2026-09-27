<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\PreValidation\TissGuideValidationResult;
use App\Enums\{BillingClaimStatus, EntityGate};
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\PresentsTissValidation;
use App\Http\Requests\Financial\{AttachClaimsToBatchRequest, BatchReceiptRequest, BulkClaimReceiptRequest};
use App\Models\{BillingBatch, BillingClaim, Entity};
use App\Services\Financial\{BillingAdjustmentService, BillingBatchAttachService, BillingBulkReceiptService};
use Illuminate\Database\Eloquent\{Builder, Collection as EloquentCollection};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Ações em lote do faturamento (JSON — a tela mostra o resultado no modal):
 *  - "Adicionar guias" / "Reprocessar pendentes" (lote) e "Incluir em lote"
 *    (guia) — BillingBatchAttachService;
 *  - "Registrar recebimento" das guias selecionadas e do lote inteiro —
 *    BillingBulkReceiptService.
 * Mesma autorização das ações vizinhas (grupo financeiro na rota e throttle
 * financial-write nas gravações; Gate do financeiro aqui); a clínica vem da
 * sessão, é conferida no binding e de novo aqui — os services releem tudo sob
 * lock, escopado por ela. Nenhum dado de paciente vai para log.
 */
class BillingBulkActionsController extends Controller
{
    use PresentsTissValidation;

    public function __construct(
        private readonly BillingBatchAttachService $attach,
        private readonly BillingBulkReceiptService $receipts,
        private readonly BillingAdjustmentService $adjustments,
    ) {
        $this->titleController = 'Faturamento TISS';
    }

    /** Guias elegíveis do modal "Adicionar guias" (até MAX_CLAIMS; `total` = todas). */
    public function attachableClaims(BillingBatch $batch): JsonResponse
    {
        $this->authorizeFor((string) $batch->entity_id);

        $batch->loadMissing('tissBatch');
        $error = $this->attach->batchAttachError($batch, $batch->tissBatch);

        if ($error !== null) {
            $this->adjustments->throwBatchError($batch, $error);
        }

        $query  = $this->attach->attachableClaimsQuery($batch, $batch->tissBatch?->operator_id);
        $total  = (clone $query)->count();
        $claims = $query->with(['patient.person', 'batch', 'tissGuide'])
            ->orderBy('billing_claims.attendance_date')
            ->orderBy('billing_claims.id')
            ->limit(BillingBatchAttachService::MAX_CLAIMS)
            ->get();

        return response()->json([
            'data' => $claims->map(fn (BillingClaim $claim): array => [
                'id'                => $claim->id,
                'code'              => $claim->code,
                'attendance_date'   => $claim->attendance_date?->toDateString(),
                'patient_name'      => $claim->patient?->person?->full_name,
                'amount'            => (float) $claim->amount,
                'origin'            => $this->origin($claim, $batch),
                'origin_batch_code' => $this->origin($claim, $batch) === 'other' ? $claim->batch?->code : null,
                'has_errors'        => $this->guideHasErrors($claim->tissGuide),
            ])->values(),
            'total' => $total,
            'max'   => BillingBatchAttachService::MAX_CLAIMS,
        ]);
    }

    /** Lotes de destino de "Incluir em lote": em rascunho, TISS, do convênio (e operadora) da guia. */
    public function attachTargets(BillingClaim $claim): JsonResponse
    {
        $this->authorizeFor((string) $claim->entity_id);

        $claim->loadMissing(['tissGuide', 'batch']);
        $error = $this->attach->claimAttachErrorFor($claim);

        if ($error !== null) {
            $this->adjustments->throwClaimError($claim, $error);
        }

        $targets = $this->attach
            ->attachTargetsQuery((string) $claim->entity_id, $claim->covenant_id, $claim->tissGuide?->operator_id)
            ->withCount(['claims' => fn (Builder $q) => $q->where('billing_claims.status', '!=', BillingClaimStatus::Cancelled->value)])
            ->orderByDesc('billing_batches.created_at')
            ->orderByDesc('billing_batches.id')
            ->limit(BillingBatchAttachService::MAX_TARGETS)
            ->get();

        return response()->json([
            'data' => $targets->map(fn (BillingBatch $batch): array => [
                'id'           => $batch->id,
                'code'         => $batch->code,
                'period_start' => $batch->period_start?->toDateString(),
                'period_end'   => $batch->period_end?->toDateString(),
                'claims_count' => (int) $batch->claims_count,
                'total_amount' => (float) $batch->total_amount,
                'is_current'   => (string) $batch->id === (string) $claim->batch_id,
                'attach_url'   => route('panel.financial.billing.batches.attach-claims', $batch->id),
            ])->values(),
        ]);
    }

    /** "Adicionar guias" (lote) e "Incluir em lote" (guia): resultado por guia. */
    public function attachClaims(AttachClaimsToBatchRequest $request, BillingBatch $batch): JsonResponse
    {
        $this->authorizeFor((string) $batch->entity_id);

        return response()->json($this->attachPayload($this->attach->attachClaims($batch, (array) $request->validated('claim_ids'))));
    }

    /** "Reprocessar pendentes": resultado por guia. */
    public function reprocessPending(BillingBatch $batch): JsonResponse
    {
        $this->authorizeFor((string) $batch->entity_id);

        return response()->json($this->attachPayload($this->attach->reprocessPending($batch)));
    }

    /** "Registrar recebimento" das guias selecionadas (valor por guia). */
    public function bulkReceipt(BulkClaimReceiptRequest $request): JsonResponse
    {
        $entity = $this->authorizeFor(null);
        $result = $this->receipts->payClaims((string) $entity->id, $request->amounts(), $request->receiptData());

        return response()->json($this->receiptPayload($result));
    }

    /** Prévia de "Registrar recebimento do lote": quantidade e total. */
    public function batchReceiptPreview(BillingBatch $batch): JsonResponse
    {
        $this->authorizeFor((string) $batch->entity_id);

        return response()->json($this->receipts->batchPreview($batch));
    }

    /** "Registrar recebimento do lote": todas as guias pagáveis pelo valor a receber. */
    public function batchReceipt(BatchReceiptRequest $request, BillingBatch $batch): JsonResponse
    {
        $this->authorizeFor((string) $batch->entity_id);

        return response()->json($this->receiptPayload($this->receipts->payBatch($batch, $request->receiptData())));
    }

    /**
     * @param array{batch: BillingBatch, results: list<array{claim: BillingClaim, validation: TissGuideValidationResult, attached: bool}>, remaining: int} $result
     *
     * @return array<string, mixed>
     */
    private function attachPayload(array $result): array
    {
        $batch    = $result['batch'];
        $results  = $result['results'];
        $attached = count(array_filter($results, fn (array $r): bool => $r['attached']));
        $pending  = count($results) - $attached;

        // Nome do paciente no resultado de cada guia, sem 1 consulta por guia.
        EloquentCollection::make(array_column($results, 'claim'))->load('patient.person');

        $message = match (true) {
            $pending === 0  => __('financial_billing.attach_result.all', ['count' => $attached, 'batch' => $batch->code]),
            $attached === 0 => __('financial_billing.attach_result.none', ['batch' => $batch->code]),
            default         => __('financial_billing.attach_result.partial', ['attached' => $attached, 'pending' => $pending, 'batch' => $batch->code]),
        };

        if ($result['remaining'] > 0) {
            $message .= ' ' . __('financial_billing.attach_result.remaining', ['count' => $result['remaining'], 'max' => BillingBatchAttachService::MAX_CLAIMS]);
        }

        return [
            'message'        => $message,
            'attached_count' => $attached,
            'pending_count'  => $pending,
            'remaining'      => $result['remaining'],
            'results'        => array_map(fn (array $r): array => [
                'claim_id'     => $r['claim']->id,
                'code'         => $r['claim']->code,
                'patient_name' => $r['claim']->patient?->person?->full_name,
                'attached'     => $r['attached'],
                'validation'   => $this->validationPayload($r['validation']),
            ], $results),
        ];
    }

    /**
     * @param array{paid: list<BillingClaim>, skipped: list<BillingClaim>, total: float} $result
     *
     * @return array<string, mixed>
     */
    private function receiptPayload(array $result): array
    {
        $paid    = count($result['paid']);
        $skipped = count($result['skipped']);

        return [
            'message' => match (true) {
                $paid === 0  => __('financial_billing.receipt_result.none'),
                $skipped > 0 => __('financial_billing.receipt_result.paid_skipped', ['count' => $paid, 'skipped' => $skipped]),
                default      => __('financial_billing.receipt_result.paid', ['count' => $paid]),
            },
            'paid' => array_map(fn (BillingClaim $claim): array => [
                'claim_id'    => $claim->id,
                'code'        => $claim->code,
                'paid_amount' => (float) $claim->paid_amount,
            ], $result['paid']),
            'skipped' => array_map(fn (BillingClaim $claim): array => [
                'claim_id' => $claim->id,
                'code'     => $claim->code,
                'message'  => __('financial_billing.receipt_result.skipped_item', ['code' => $claim->code]),
            ], $result['skipped']),
            'total_paid' => $result['total'],
        ];
    }

    /** @return 'individual'|'this'|'other' de onde vem a guia elegível */
    private function origin(BillingClaim $claim, BillingBatch $batch): string
    {
        return match (true) {
            $claim->batch_id === null                         => 'individual',
            (string) $claim->batch_id === (string) $batch->id => 'this',
            default                                           => 'other',
        };
    }

    /**
     * Gate do financeiro na clínica da sessão e o registro (quando há) tem de
     * ser dela — o binding já escopa; aqui é a segunda barreira (404, sem
     * confirmar que o registro existe em outra clínica).
     */
    private function authorizeFor(?string $recordEntityId): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        abort_unless($recordEntityId === null || $recordEntityId === (string) $entity->id, 404);

        return $entity;
    }
}
