<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\PresentsTissValidation;
use App\Http\Requests\Financial\{BillingCancelRequest, FixPendingGuideRequest};
use App\Models\{BillingBatch, BillingClaim, Entity};
use App\Services\Financial\BillingAdjustmentService;
use Illuminate\Http\{JsonResponse, RedirectResponse};
use Illuminate\Support\Facades\Gate;

/**
 * Ações de correção do faturamento fora do fluxo feliz: cancelar guia ou lote
 * em rascunho (motivo obrigatório) e "Corrigir pendência" da guia TISS que
 * ficou fora do lote. Mesma autorização das ações vizinhas (grupo financeiro
 * + throttle financial-write na rota, Gate do financeiro aqui); a clínica vem
 * da sessão, é conferida no binding e de novo aqui — e o service relê tudo sob
 * lock, escopado por ela. Regras: BillingAdjustmentService; ordem de locks:
 * BillingService.
 */
class BillingClaimActionsController extends Controller
{
    use PresentsTissValidation;

    public function __construct(
        private readonly BillingAdjustmentService $adjustments,
    ) {
        $this->titleController = 'Faturamento TISS';
    }

    public function cancelClaim(BillingCancelRequest $request, BillingClaim $claim): RedirectResponse
    {
        $this->authorizeFor((string) $claim->entity_id);

        $claim = $this->adjustments->cancelClaim($claim, (string) $request->validated('reason'), $this->userId($request));

        return back()->with('success', __('financial_billing.flash.claim_cancelled', ['code' => $claim->code]));
    }

    public function cancelBatch(BillingCancelRequest $request, BillingBatch $batch): RedirectResponse
    {
        $this->authorizeFor((string) $batch->entity_id);

        $batch = $this->adjustments->cancelBatch($batch, (string) $request->validated('reason'), $this->userId($request));

        return back()->with('success', __('financial_billing.flash.batch_cancelled', ['code' => $batch->code]));
    }

    /**
     * JSON (a tela mostra o resultado dentro do modal): dados salvos, o
     * resultado da pré-validação refeita (mesmo formato de
     * TissGuidePreValidateController) e se a guia entrou no lote TISS.
     */
    public function fixPending(FixPendingGuideRequest $request, BillingClaim $claim): JsonResponse
    {
        $this->authorizeFor((string) $claim->entity_id);

        ['claim' => $claim, 'validation' => $validation, 'attached' => $attached] = $this->adjustments
            ->fixPendingGuide($claim, $request->validated());

        $batchCode = $claim->batch_id ? $claim->batch?->code : null;

        return response()->json([
            'message' => match (true) {
                $attached                => __('financial_billing.fix_result.attached', ['code' => $claim->code, 'batch' => $batchCode]),
                $validation->hasErrors() => __('financial_billing.fix_result.still_pending', ['code' => $claim->code]),
                default                  => __('financial_billing.fix_result.saved', ['code' => $claim->code]),
            },
            'attached'   => $attached,
            'validation' => $this->validationPayload($validation),
        ]);
    }

    /**
     * Gate do financeiro na clínica da sessão e o registro tem de ser dela (o
     * binding já escopa; aqui é a segunda barreira — 404, sem confirmar que o
     * registro existe em outra clínica).
     */
    private function authorizeFor(string $recordEntityId): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        abort_unless($recordEntityId === (string) $entity->id, 404);

        return $entity;
    }

    /** Quem cancelou (cancelled_by). */
    private function userId(BillingCancelRequest $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
