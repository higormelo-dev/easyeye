<?php

namespace App\Services\Billing;

use App\Models\Billing\GatewayFallbackRule;

class FallbackGatewayService
{
    public function resolve(
        ?string $entityId,
        ?string $planId,
        string $primaryGatewayCode,
        string $triggerType,
    ): ?string {
        $query = GatewayFallbackRule::query()
            ->where('active', true)
            ->where('primary_gateway_code', $primaryGatewayCode)
            ->where('trigger_type', $triggerType)
            ->orderBy('priority');

        if ($entityId) {
            $query->where(function ($q) use ($entityId): void {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            });
        }

        if ($planId) {
            $query->where(function ($q) use ($planId): void {
                $q->where('plan_id', $planId)->orWhereNull('plan_id');
            });
        }

        return $query->value('fallback_gateway_code');
    }
}
