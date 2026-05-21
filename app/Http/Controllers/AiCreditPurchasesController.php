<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Services\{AiCreditPurchaseService, AiCreditWalletService};
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Enums\{ClientRule, FeatureKey};
use App\Models\Subscription;
use App\Services\FeatureGateService;
use DomainException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class AiCreditPurchasesController extends Controller
{
    public function __construct(
        private readonly AiCreditPurchaseService $purchaseService,
        private readonly AiCreditWalletService $walletService,
        private readonly FeatureGateService $featureGate,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        $this->assertAiFeatureEnabled($entityId);
        $this->authorizeCreditPurchase();

        $packageCodes = collect($this->purchaseService->packages())
            ->pluck('code')
            ->filter()
            ->values()
            ->all();

        $validated = $request->validate([
            'package_code'    => ['required', 'string', Rule::in($packageCodes)],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $subscriptionId = $this->activeSubscriptionId($entityId);

        abort_if($subscriptionId === null, 422, __('ai.credit_purchase_requires_subscription'));

        try {
            $purchase = $this->purchaseService->createPendingPurchase(
                entityId: $entityId,
                packageCode: (string) $validated['package_code'],
                subscriptionId: $subscriptionId,
                requestedBy: (string) auth()->id(),
                idempotencyKey: $validated['idempotency_key'] ?? null,
            );

            if ((bool) config('ai.credit_purchases.auto_credit_without_gateway', false)) {
                $purchase = $this->purchaseService->creditPaidPurchase($purchase, (string) auth()->id());
            }
        } catch (DomainException) {
            return response()->json([
                'message' => __('ai.credit_purchase_unavailable'),
            ], 422);
        }

        $status = $purchase->status instanceof AiCreditPurchaseStatus
            ? $purchase->status
            : AiCreditPurchaseStatus::tryFrom((string) $purchase->status);

        $message = $status === AiCreditPurchaseStatus::Credited
            ? __('ai.credit_purchase_credited')
            : __('ai.credit_purchase_pending');

        return response()->json([
            'message'  => $message,
            'purchase' => $this->purchaseService->presentPurchase($purchase),
            'balance'  => $this->walletService->balance($entityId),
        ], 201);
    }

    private function assertAiFeatureEnabled(string $entityId): void
    {
        $hasExamAssistant  = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $hasReportDrafting = $this->featureGate->can($entityId, FeatureKey::HasAiReportDrafting);

        abort_if(! $hasExamAssistant && ! $hasReportDrafting, 403, __('ai.feature_unavailable'));
    }

    private function authorizeCreditPurchase(): void
    {
        abort_unless(
            session('selected_entity_user_rule') === ClientRule::Admin->value,
            403,
        );
    }

    private function activeSubscriptionId(string $entityId): ?string
    {
        return Subscription::query()
            ->forEntity($entityId)
            ->accessible()
            ->latest('created_at')
            ->value('id');
    }
}
