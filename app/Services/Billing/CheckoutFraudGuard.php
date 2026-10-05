<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\CheckoutException;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Log, RateLimiter};

/**
 * Antifraude do cartão no checkout (teste de cartões roubados — "card
 * testing"). Conta RECUSAS (não tentativas): o limite de requisições
 * (billing-checkout-pay) já segura a cadência; aqui o que pesa é o cartão
 * recusado, que é o sinal de abuso.
 *
 *  - por IP (hora e dia) e por clínica (dia): cadastro e painel;
 *  - global por hora, separado para o cadastro e o painel: um ataque
 *    distribuído (vários IPs, contas novas) desliga o cartão por um tempo
 *    sem afetar Pix/boleto;
 *  - no cadastro (antes de confirmar o e-mail): depois de
 *    billing.checkout.fraud.signup_unverified_declines recusa(s) o cartão
 *    exige o e-mail confirmado. Escolha: exigir o e-mail ANTES do 1º
 *    pagamento quebraria o fluxo "contratar já pagando" (o cliente teria de
 *    sair para a caixa de entrada no meio do cadastro); assim o cliente
 *    legítimo paga direto e o robô ganha uma única tentativa por conta,
 *    somada aos limites por IP, ao Turnstile no /register e ao limite de
 *    cadastros por IP.
 *
 * Estourado: 429 card_attempts_exceeded (ou 403 card_requires_verified_email)
 * — Pix e boleto seguem disponíveis.
 */
class CheckoutFraudGuard
{
    private const HOUR = 3600;

    private const DAY = 86400;

    public function assertCardAllowed(Request $request, Entity $entity, bool $signup): void
    {
        $config = (array) config('billing.checkout.fraud', []);
        $ip     = (string) $request->ip();

        $blocked = RateLimiter::tooManyAttempts($this->key('ip-hour', $ip), (int) ($config['declines_per_ip_hour'] ?? 3))
            || RateLimiter::tooManyAttempts($this->key('ip-day', $ip), (int) ($config['declines_per_ip_day'] ?? 6))
            || RateLimiter::tooManyAttempts($this->key('entity-day', (string) $entity->id), (int) ($config['declines_per_entity_day'] ?? 5))
            || RateLimiter::tooManyAttempts($this->globalKey($signup), (int) ($config[$signup ? 'signup_declines_global_hour' : 'panel_declines_global_hour'] ?? ($signup ? 20 : 60)));

        if ($blocked) {
            throw CheckoutException::make('card_attempts_exceeded', 429);
        }

        if ($signup && ! $request->user()?->hasVerifiedEmail()
            && RateLimiter::attempts($this->key('signup-entity', (string) $entity->id)) >= max(0, (int) ($config['signup_unverified_declines'] ?? 1))) {
            throw CheckoutException::make('card_requires_verified_email', 403);
        }
    }

    public function recordDecline(Request $request, Entity $entity, bool $signup): void
    {
        $ip = (string) $request->ip();

        RateLimiter::hit($this->key('ip-hour', $ip), self::HOUR);
        RateLimiter::hit($this->key('ip-day', $ip), self::DAY);
        RateLimiter::hit($this->key('entity-day', (string) $entity->id), self::DAY);
        RateLimiter::hit($this->globalKey($signup), self::HOUR);

        if ($signup) {
            RateLimiter::hit($this->key('signup-entity', (string) $entity->id), 7 * self::DAY);
        }

        Log::notice('Checkout: cartão recusado (antifraude).', ['entity_id' => $entity->id, 'signup' => $signup, 'ip_hash' => hash('sha256', $ip)]);
    }

    private function key(string $scope, string $value): string
    {
        return 'checkout-fraud:' . $scope . ':' . hash('sha256', $value);
    }

    private function globalKey(bool $signup): string
    {
        return 'checkout-fraud:global:' . ($signup ? 'signup' : 'panel');
    }
}
