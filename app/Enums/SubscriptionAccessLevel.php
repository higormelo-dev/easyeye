<?php

namespace App\Enums;

/**
 * Quanto do painel a empresa pode usar pela assinatura (Subscription::accessLevel).
 *
 * - Full: tudo que o plano inclui (trial, cortesia ou plano em dia; cliente
 *   pagante em atraso há menos de `billing.dunning.soft_block_after_days`);
 * - Limited: cliente pagante em atraso dentro da régua — agenda, pacientes e
 *   prontuário seguem; IA e módulo financeiro ficam bloqueados;
 * - None: sem acesso (vai para /subscription/expired).
 */
enum SubscriptionAccessLevel: string
{
    case Full    = 'full';
    case Limited = 'limited';
    case None    = 'none';

    /**
     * Status HTTP de quem é barrado pela assinatura (sem acesso ou acesso
     * limitado) — o mesmo no CheckSubscription e na negação do FeatureGate,
     * com `access_level` no corpo JSON.
     */
    public const DENIED_HTTP_STATUS = 402;

    /** Mensagem para quem é barrado neste nível. */
    public function deniedMessage(): string
    {
        return $this === self::Limited
            ? __('subscriptions.access_limited')
            : __('subscriptions.access_blocked');
    }

    public function hasAccess(): bool
    {
        return $this !== self::None;
    }

    /** Ordem para escolher o melhor nível entre várias assinaturas. */
    public function rank(): int
    {
        return match ($this) {
            self::Full    => 2,
            self::Limited => 1,
            self::None    => 0,
        };
    }
}
