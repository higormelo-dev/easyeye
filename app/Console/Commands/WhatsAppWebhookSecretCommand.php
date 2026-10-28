<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsApp\WhatsAppSetting;
use Illuminate\Console\Command;

/**
 * Dados para cadastrar à mão o webhook do app GLOBAL na Gupshup (conta comum, sem
 * Partner API: não há como registrar por API). Painel Gupshup → app → Webhooks →
 * Adicionar webhook: URL abaixo, formato Meta (v3), "Inclui cabeçalhos" com o par
 * cabeçalho/valor impresso aqui. O valor é o segredo cifrado do app — só sai neste
 * terminal, nunca por HTTP.
 */
class WhatsAppWebhookSecretCommand extends Command
{
    protected $signature = 'whatsapp:webhook-secret
        {--registered : Marca o webhook como cadastrado no painel (some o aviso do Manager)}';

    protected $description = 'Mostra URL, cabeçalho e segredo para cadastrar o webhook do WhatsApp no painel da Gupshup';

    public function handle(): int
    {
        $setting = WhatsAppSetting::globalSetting();

        if (! $setting || ! $setting->hasApp()) {
            $this->error('Cadastre o App ID do número global em Manager → WhatsApp antes.');

            return self::FAILURE;
        }

        if (blank($setting->webhook_secret)) {
            $setting->webhook_secret = WhatsAppSetting::generateWebhookSecret();
            $setting->save();
        }

        if ($this->option('registered')) {
            $setting->forceFill(['webhook_subscribed_at' => now()])->save();
        }

        $this->line('URL:        ' . route('whatsapp.gupshup.webhook', $setting->webhook_token));
        $this->line('Formato:    Meta (v3)');
        $this->line('Cabeçalho:  ' . WhatsAppSetting::WEBHOOK_SECRET_HEADER);
        $this->line('Valor:      ' . $setting->webhook_secret);

        return self::SUCCESS;
    }
}
