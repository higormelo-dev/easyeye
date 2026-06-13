<?php

namespace App\Actions\Register;

use App\Models\{Entity, Plan, Subscription, SubscriptionSetting};
use App\Services\TrialService;
use RuntimeException;

class StartTrialAction
{
    public function __construct(
        private readonly TrialService $trialService,
    ) {
    }

    /**
     * Inicia o trial com o plano escolhido pelo usuário (ou o menor tier disponível).
     */
    public function execute(Entity $entity, ?string $planId = null): Subscription
    {
        $plan = $planId
            ? Plan::active()->find($planId)
            : null;

        // Fallback: menor tier ativo se o plano não existir ou estiver inativo
        $plan ??= Plan::active()->orderBy('sort_order')->first()
            ?? throw new RuntimeException('Nenhum plano ativo encontrado.');

        $days = SubscriptionSetting::trialDays();

        if ($days === 0) {
            throw new RuntimeException('Período de trial está desabilitado (trial_days = 0).');
        }

        return $this->trialService->startManualTrial($entity, $plan, $days);
    }
}
