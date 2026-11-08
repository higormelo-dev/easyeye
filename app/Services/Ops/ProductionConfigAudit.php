<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\Security\TurnstileVerifier;

/**
 * O que está simulado ou desligado na configuração e, em produção, não deveria estar. Cada item é um
 * jeito de o sistema "funcionar" sem avisar: captcha aprovando tudo, lote TISS "aceito" sem sair,
 * WhatsApp e e-mail só no log, IA de mentira. Só lê config (nada de rede nem de banco).
 * Usado por ops:check-production-config.
 */
final class ProductionConfigAudit
{
    /** @return list<string> códigos dos achados (chaves de lang/*\/ops_alerts.php → findings) */
    public static function findings(): array
    {
        $found = [];

        if (! TurnstileVerifier::enabled()) {
            $found[] = 'turnstile_off';
        }

        if (config('tiss.transport.driver') !== 'http') {
            $found[] = 'tiss_simulated';
        }

        if (config('whatsapp.driver') !== 'gupshup') {
            $found[] = 'whatsapp_simulated';
        } elseif (config('whatsapp.gupshup.sandbox')) {
            $found[] = 'whatsapp_sandbox';
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $found[] = 'mail_not_sending';
        }

        if (config('ai.provider_runtime') !== 'real') {
            $found[] = 'ai_fake';
        }

        return $found;
    }
}
