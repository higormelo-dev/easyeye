<?php

declare(strict_types = 1);

namespace App\Observers;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\{PartnerService, ReferralService};
use Illuminate\Support\Facades\Log;

/**
 * Dispara eventos de CAC quando uma assinatura muda de estado.
 *
 * - Trial criado  → registra evento de indicação (se veio de referral code)
 * - Convertido    → gera comissão do parceiro + aplica recompensa de indicação
 */
class SubscriptionObserver
{
    public function __construct(
        private readonly PartnerService $partnerService,
        private readonly ReferralService $referralService,
    ) {
    }

    /**
     * Trial iniciado → registra evento ReferralEventType::TrialStarted.
     */
    public function created(Subscription $subscription): void
    {
        if (!$subscription->isOnTrial()) {
            return;
        }

        $this->handleReferralTrial($subscription);
    }

    /**
     * Assinatura convertida para paga → comissão de parceiro + reward de indicação.
     */
    public function updated(Subscription $subscription): void
    {
        if (!$subscription->wasChanged('status')) {
            return;
        }

        if ($subscription->status !== SubscriptionStatus::Active) {
            return;
        }

        $this->handlePartnerCommission($subscription);
        $this->handleReferralConversion($subscription);
    }

    // -------------------------------------------------------------------------

    private function handleReferralTrial(Subscription $subscription): void
    {
        try {
            $entity = $subscription->entity;

            if (!$entity->referral_code_id) {
                return;
            }

            $referralCode = $entity->referralCode;

            if (!$referralCode) {
                return;
            }

            $this->referralService->recordTrialStarted($referralCode, $entity);
        } catch (\Throwable $e) {
            Log::error('SubscriptionObserver: falha ao registrar trial de indicação.', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function handlePartnerCommission(Subscription $subscription): void
    {
        try {
            $this->partnerService->generateCommission($subscription);
        } catch (\Throwable $e) {
            Log::error('SubscriptionObserver: falha ao gerar comissão de parceiro.', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function handleReferralConversion(Subscription $subscription): void
    {
        try {
            $entity = $subscription->entity;

            if (!$entity->referral_code_id) {
                return;
            }

            $referralCode = $entity->referralCode;

            if (!$referralCode) {
                return;
            }

            $this->referralService->recordConversion($referralCode, $entity, $subscription);
        } catch (\Throwable $e) {
            Log::error('SubscriptionObserver: falha ao processar conversão de indicação.', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
