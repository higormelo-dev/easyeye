<?php

namespace App\Enums;

/**
 * Eventos rastreados no ciclo de vida de uma indicação.
 * Permite calcular o funil: indicação → trial → conversão.
 */
enum ReferralEventType: string
{
    case TrialStarted  = 'trial_started';   // Indicado iniciou trial
    case PlanActivated = 'plan_activated';  // Indicado converteu para plano pago

    public function label(): string
    {
        return match ($this) {
            self::TrialStarted  => 'Trial Iniciado',
            self::PlanActivated => 'Plano Ativado',
        };
    }

    /** Apenas conversão gera recompensa para o referenciador */
    public function triggersReward(): bool
    {
        return $this === self::PlanActivated;
    }
}
