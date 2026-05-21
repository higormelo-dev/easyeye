<?php

declare(strict_types = 1);

namespace App\Observers;

use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\{PartnerService, ReferralService};
use Illuminate\Support\Facades\Log;

/**
 * Dispara eventos de CAC e provisionamento ao mudar o estado de uma assinatura.
 *
 * - Trial criado          → registra evento de indicação (se veio de referral code)
 * - Convertida para Active → comissão de parceiro + reward de indicação + grant IA
 * - ends_at avança         → grant IA do novo ciclo (renovação)
 */
class SubscriptionObserver
{
    public function __construct(
        private readonly PartnerService $partnerService,
        private readonly ReferralService $referralService,
        private readonly AiCreditWalletService $aiCreditWalletService,
    ) {
    }

    /**
     * Trial iniciado → registra evento ReferralEventType::TrialStarted.
     * Assinatura paga criada já como Active → concede franquia IA imediatamente.
     */
    public function created(Subscription $subscription): void
    {
        if ($subscription->isOnTrial()) {
            $this->handleReferralTrial($subscription);

            return;
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            $this->handleAiMonthlyGrant($subscription);
        }
    }

    /**
     * Mudança de status para Active → CAC + grant IA do ciclo.
     * Renovação (ends_at avança) com status Active → grant IA do novo ciclo.
     */
    public function updated(Subscription $subscription): void
    {
        $statusBecameActive = $subscription->wasChanged('status')
            && $subscription->status === SubscriptionStatus::Active;

        if ($statusBecameActive) {
            $this->handlePartnerCommission($subscription);
            $this->handleReferralConversion($subscription);
        }

        $endsAtAdvanced = $subscription->wasChanged('ends_at')
            && $subscription->status === SubscriptionStatus::Active
            && $this->endsAtMovedForward($subscription);

        if ($statusBecameActive || $endsAtAdvanced) {
            $this->handleAiMonthlyGrant($subscription);
        }
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

    private function handleAiMonthlyGrant(Subscription $subscription): void
    {
        try {
            $this->aiCreditWalletService->grantMonthlyCreditsForSubscription($subscription);
        } catch (\Throwable $e) {
            Log::error('SubscriptionObserver: falha ao conceder créditos IA do ciclo.', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function endsAtMovedForward(Subscription $subscription): bool
    {
        $original = $subscription->getOriginal('ends_at');
        $current  = $subscription->ends_at;

        if ($current === null) {
            return $original !== null;
        }

        if ($original === null) {
            return true;
        }

        $originalAt = $original instanceof \DateTimeInterface
            ? $original
            : new \DateTimeImmutable((string) $original);

        return $current->getTimestamp() > $originalAt->getTimestamp();
    }
}
