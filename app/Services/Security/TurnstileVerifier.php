<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\{Http, Log};
use Illuminate\Support\Str;
use Throwable;

/**
 * Cloudflare Turnstile (captcha) no cadastro do site — ligado só quando
 * TURNSTILE_SITE_KEY e TURNSTILE_SECRET_KEY estão configuradas.
 *
 * Validação no servidor (obrigatória — o token sozinho não prova nada):
 * POST https://challenges.cloudflare.com/turnstile/v0/siteverify com secret,
 * response (token do widget, até 2048 caracteres, vale 5 minutos e uma
 * única validação), remoteip e idempotency_key; resposta {success,
 * "error-codes", hostname, …}.
 * https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
class TurnstileVerifier
{
    public static function enabled(): bool
    {
        return filled(config('billing.turnstile.site_key')) && filled(config('billing.turnstile.secret_key'));
    }

    public static function siteKey(): ?string
    {
        return self::enabled() ? (string) config('billing.turnstile.site_key') : null;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post((string) config('billing.turnstile.verify_url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'), array_filter([
                    'secret'          => (string) config('billing.turnstile.secret_key'),
                    'response'        => $token,
                    'remoteip'        => $ip,
                    'idempotency_key' => (string) Str::uuid(),
                ]));
        } catch (Throwable $e) {
            // Sem resposta da Cloudflare: falha fechada (o cadastro pede o
            // captcha de novo) — o motivo fica no log, nunca o token.
            Log::warning('Turnstile: falha ao validar o token.', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->json('success') === true) {
            return true;
        }

        Log::notice('Turnstile: token recusado.', ['error_codes' => (array) $response->json('error-codes', [])]);

        return false;
    }
}
