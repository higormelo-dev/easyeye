<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Providers;

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\TemplateMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simulação (WHATSAPP_DRIVER=mock ou vazio): nenhum HTTP sai — loga (sem o
 * telefone inteiro nem o código de verificação) e devolve id fake. Mesmo
 * padrão do transporte TISS mock. O app ainda precisa estar cadastrado no
 * manager (o fluxo é o mesmo do real).
 */
class MockWhatsAppProvider implements WhatsAppProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function authorize(WhatsAppSetting $setting): array
    {
        if (blank($setting->app_id)) {
            return ['ok' => false, 'error' => 'App Gupshup não configurado.', 'error_code' => 'missing_app', 'retryable' => false];
        }

        return ['ok' => true];
    }

    public function sendTemplate(WhatsAppSetting $setting, string $phone, TemplateMessage $template): array
    {
        if (blank($setting->app_id)) {
            return ['ok' => false, 'error' => 'App Gupshup não configurado.', 'error_code' => 'missing_app', 'retryable' => false];
        }

        Log::info('[whatsapp:mock] template', [
            'template' => $template->name,
            'language' => $template->language,
            'phone'    => Str::mask($phone, '*', 0, -4),
        ]);

        return ['ok' => true, 'message_id' => 'mock-' . Str::uuid()->toString()];
    }

    public function sendSessionText(WhatsAppSetting $setting, string $phone, string $text): array
    {
        if (blank($setting->app_id)) {
            return ['ok' => false, 'error' => 'App Gupshup não configurado.', 'error_code' => 'missing_app', 'retryable' => false];
        }

        Log::info('[whatsapp:mock] text', ['phone' => Str::mask($phone, '*', 0, -4), 'text' => Str::limit($text, 120)]);

        return ['ok' => true, 'message_id' => 'mock-' . Str::uuid()->toString()];
    }

    public function health(WhatsAppSetting $setting): array
    {
        return ['ok' => true, 'healthy' => true];
    }

    public function subscribeWebhook(WhatsAppSetting $setting, string $url, string $secret): array
    {
        Log::info('[whatsapp:mock] subscription', ['app_id' => $setting->app_id]);

        return ['ok' => true, 'subscription_id' => 'mock'];
    }
}
