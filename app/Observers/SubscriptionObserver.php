<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\{PartnerService, ReferralService};
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispara eventos de CAC e provisionamento ao mudar o estado de uma assinatura.
 *
 * - Trial criado          → registra evento de indicação (se veio de referral code)
 * - Convertida para Active → comissão de parceiro + reward de indicação (só se
 *   gera receita — cobrança automática — e só na 1ª ativação paga: voltar
 *   de "em atraso" ao pagar a renovação não é venda nova)
 * - Franquia de IA: só cobrança automática paga com acesso total
 *   (Subscription::earnsMonthlyAiQuota). Na ativação (ou ao voltar a ficar
 *   ativa/paga) concede a janela mensal atual, se ainda não concedida — a
 *   concessão é idempotente por janela, então renovação e "Adicionar
 *   período" não reiniciam o consumo; as janelas seguintes vêm do
 *   ai:grant-monthly-quotas. Troca de plano aplica a cota do novo plano
 *   mantendo o consumido.
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
     * Assinatura paga criada já como Active → concede a franquia IA da janela.
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
     * Mudança de status para Active → CAC + franquia IA da janela atual.
     * Renovação (ends_at avança) → garante a janela atual (sem reiniciar o
     * consumo de uma janela já concedida). Troca de plano → cota do novo
     * plano, mantendo o consumido.
     */
    public function updated(Subscription $subscription): void
    {
        $statusBecameActive = $subscription->wasChanged('status')
            && $subscription->status === SubscriptionStatus::Active;

        // Cortesia não é venda: sem comissão de parceiro nem recompensa de
        // indicação (nem franquia de IA — ver earnsMonthlyAiQuota).
        // Só a 1ª ativação paga conta: a renovação paga depois de um atraso
        // também volta para Active, mas a assinatura já tinha pagamento.
        if ($statusBecameActive && $subscription->isBillable() && $this->isFirstPaidActivation($subscription)) {
            $this->handlePartnerCommission($subscription);
            $this->handleReferralConversion($subscription);
        }

        if ($subscription->wasChanged('plan_id')) {
            $this->handleAiPlanChange($subscription);

            return;
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

            if (! $entity->referral_code_id) {
                return;
            }

            $referralCode = $entity->referralCode;

            if (! $referralCode) {
                return;
            }

            $this->referralService->recordTrialStarted($referralCode, $entity);
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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

            if (! $entity->referral_code_id) {
                return;
            }

            $referralCode = $entity->referralCode;

            if (! $referralCode) {
                return;
            }

            $this->referralService->recordConversion($referralCode, $entity, $subscription);
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
            Log::error('SubscriptionObserver: falha ao conceder créditos IA do ciclo.', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function handleAiPlanChange(Subscription $subscription): void
    {
        try {
            $this->aiCreditWalletService->applyPlanChangeForSubscription($subscription);
        } catch (Throwable $e) {
            Log::error('SubscriptionObserver: falha ao ajustar a cota IA à troca de plano.', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /** Nenhum pagamento confirmado antes desta mudança (last_payment_at era nulo). */
    private function isFirstPaidActivation(Subscription $subscription): bool
    {
        return $subscription->getOriginal('last_payment_at') === null;
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

        $originalAt = $original instanceof DateTimeInterface
            ? $original
            : new DateTimeImmutable((string) $original);

        return $current->getTimestamp() > $originalAt->getTimestamp();
    }
}
