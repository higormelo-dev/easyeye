<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{ReferralEventType, ReferralRewardType};
use App\Models\{Entity, ReferralCode, ReferralEvent, Subscription};
use Illuminate\Support\Str;

class ReferralService
{
    /**
     * Gera um código de indicação para uma clínica ativa.
     * Uma clínica pode ter múltiplos códigos (campanhas diferentes).
     *
     * @param  ReferralRewardType  $rewardType   Tipo de recompensa para o referenciador
     * @param  int                 $rewardValue  Dias (TrialExtension) ou % (Discount)
     * @param  int|null            $maxUses       null = ilimitado
     */
    public function generate(
        Entity $entity,
        ReferralRewardType $rewardType = ReferralRewardType::TrialExtension,
        int $rewardValue = 30,
        ?int $maxUses = null,
        ?\DateTimeInterface $expiresAt = null,
    ): ReferralCode {
        return ReferralCode::create([
            'entity_id'    => $entity->id,
            'code'         => $this->generateUniqueCode($entity),
            'reward_type'  => $rewardType,
            'reward_value' => $rewardValue,
            'max_uses'     => $maxUses,
            'active'       => true,
            'expires_at'   => $expiresAt,
        ]);
    }

    /**
     * Valida e retorna um código de indicação pelo código alfanumérico.
     *
     * @throws \InvalidArgumentException se o código não existir ou não estiver usável
     */
    public function findUsable(string $code): ReferralCode
    {
        $referral = ReferralCode::where('code', strtoupper($code))->first();

        if (! $referral || ! $referral->isUsable()) {
            throw new \InvalidArgumentException(
                "Código de indicação '{$code}' é inválido, expirado ou esgotado."
            );
        }

        return $referral;
    }

    /**
     * Registra o evento de trial iniciado via código de indicação.
     * Chamado pelo SubscriptionObserver quando uma assinatura Trial é criada.
     */
    public function recordTrialStarted(ReferralCode $code, Entity $referredEntity): void
    {
        $this->recordEvent($code, $referredEntity, ReferralEventType::TrialStarted);

        // Incrementa o contador de uso do código
        $code->increment('uses_count');
    }

    /**
     * Registra a conversão (trial → pago) e aplica a recompensa ao referenciador.
     * Chamado pelo SubscriptionObserver quando a assinatura muda para Active.
     */
    public function recordConversion(ReferralCode $code, Entity $referredEntity, Subscription $subscription): void
    {
        $this->recordEvent($code, $referredEntity, ReferralEventType::PlanActivated);

        $this->applyReward($code, $subscription);
    }

    /**
     * Aplica a recompensa ao referenciador conforme o tipo configurado no código.
     */
    private function applyReward(ReferralCode $code, Subscription $activatedSubscription): void
    {
        $referrerEntity = $code->entity;
        $referrerSubscription = $referrerEntity->subscription;

        if (! $referrerSubscription) {
            return;
        }

        match ($code->reward_type) {
            ReferralRewardType::TrialExtension => $this->applyTrialExtension(
                $referrerSubscription,
                $code->reward_value,
                $code,
                $activatedSubscription,
            ),
            ReferralRewardType::DiscountPercentage => \Log::info('ReferralService: recompensa de desconto pendente de implementação no gateway de pagamento.', [
                'referral_code_id' => $code->id,
                'rate'             => $code->reward_value,
            ]),
        };
    }

    private function applyTrialExtension(
        Subscription $subscription,
        int $days,
        ReferralCode $code,
        Subscription $referenceSubscription,
    ): void {
        if ($subscription->isOnTrial() && $subscription->trial_ends_at !== null) {
            $subscription->update([
                'trial_ends_at' => $subscription->trial_ends_at->addDays($days),
            ]);

            \Log::info('ReferralService: trial estendido por indicação.', [
                'referrer_entity_id'  => $subscription->entity_id,
                'referred_entity_id'  => $referenceSubscription->entity_id,
                'days_added'          => $days,
                'referral_code'       => $code->code,
            ]);
        }
    }

    private function recordEvent(ReferralCode $code, Entity $referredEntity, ReferralEventType $type): void
    {
        // firstOrCreate: idempotente, um evento por tipo por código-clínica
        ReferralEvent::firstOrCreate([
            'referral_code_id'  => $code->id,
            'referred_entity_id' => $referredEntity->id,
            'event_type'        => $type->value,
        ], [
            'created_at' => now(),
        ]);
    }

    /**
     * Gera um código curto único baseado no nome da clínica + sufixo aleatório.
     * Formato: NOMECL1A2B (máx 10 caracteres, maiúsculas, sem espaços/especiais)
     */
    private function generateUniqueCode(Entity $entity): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $entity->name));
        $base = substr($base, 0, 6);

        do {
            $code = $base . strtoupper(Str::random(4));
        } while (ReferralCode::where('code', $code)->exists());

        return $code;
    }
}
