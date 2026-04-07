<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\Models\Billing\TenantGatewaySetting;
use App\Models\{Entity, Plan, Subscription};

class GatewayResolver
{
    public function __construct(
        private readonly GatewayRegistry $registry,
    ) {
    }

    public function resolveForSubscription(Subscription $subscription, ?string $requestedGateway = null): PaymentGatewayInterface
    {
        $code = $subscription->pinned_gateway
            ?? $requestedGateway
            ?? $this->resolveFromTenantSettings($subscription->entity_id, $subscription->plan_id)
            ?? config('billing.default_gateway');

        return $this->registry->get((string) $code);
    }

    public function resolveForNewPurchase(Entity $entity, Plan $plan, ?string $requestedGateway = null): PaymentGatewayInterface
    {
        $code = $requestedGateway
            ?? $this->resolveFromTenantSettings((string) $entity->id, (string) $plan->id)
            ?? config('billing.default_gateway');

        return $this->registry->get((string) $code);
    }

    private function resolveFromTenantSettings(string $entityId, ?string $planId): ?string
    {
        $planSetting = null;

        if ($planId) {
            $planSetting = TenantGatewaySetting::query()
                ->where('entity_id', $entityId)
                ->where('setting_key', $planId)
                ->with('defaultGateway')
                ->first();
        }

        if ($planSetting?->defaultGateway?->code) {
            return $planSetting->defaultGateway->code;
        }

        $defaultSetting = TenantGatewaySetting::query()
            ->where('entity_id', $entityId)
            ->where('setting_key', 'default')
            ->with('defaultGateway')
            ->first();

        return $defaultSetting?->defaultGateway?->code;
    }
}
