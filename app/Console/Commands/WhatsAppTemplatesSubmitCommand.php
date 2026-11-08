<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\GupshupSelfServeProvider;
use App\Services\WhatsApp\WhatsAppTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Cria na Gupshup os modelos do catálogo que ainda não existem no app GLOBAL (a Meta aprova depois).
 * Sem --send só MOSTRA o que seria enviado. Com --send, para no primeiro erro (uma falha de conta ou
 * de formato se repetiria nos demais e deixaria um modelo FAILED por tentativa).
 *
 * Nunca recria nem apaga: quem já existe, em qualquer estado, é pulado. Para refazer um FAILED ou
 * REJECTED, apague no painel da Gupshup — e lembre que a Meta pode segurar o nome por 30 dias.
 * Conferência depois: php artisan whatsapp:templates:status.
 */
class WhatsAppTemplatesSubmitCommand extends Command
{
    /** A Gupshup aceita 10 criações por minuto. */
    private const SECONDS_BETWEEN_CALLS = 7;

    protected $signature = 'whatsapp:templates:submit
        {--only= : Chaves do catálogo separadas por vírgula (ex.: connection_test), para começar por um}
        {--send : Envia de verdade à Gupshup/Meta (sem isto só mostra o que seria enviado)}';

    protected $description = 'Cria na Gupshup os modelos do catálogo que faltam (sem --send, só mostra o que seria enviado)';

    public function handle(WhatsAppProvider $provider, WhatsAppTemplateCatalog $catalog): int
    {
        if (! $provider instanceof GupshupSelfServeProvider) {
            $this->error('Disponível só na conta comum da Gupshup (GUPSHUP_API_KEY).');

            return self::FAILURE;
        }

        $setting = WhatsAppSetting::globalSetting();

        if (! $setting || ! $setting->hasApp()) {
            $this->error('Cadastre o App ID do número global em Manager → WhatsApp antes.');

            return self::FAILURE;
        }

        $entries = collect($catalog->entries((array) config('whatsapp.template_languages')));
        $only    = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $unknown = array_diff($only, $entries->pluck('key')->all());

        if ($unknown !== []) {
            $this->error('Chave fora do catálogo: ' . implode(', ', $unknown) . '. Válidas: ' . $entries->pluck('key')->implode(', ') . '.');

            return self::FAILURE;
        }

        $result = $provider->templateStatuses($setting);

        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Não foi possível ler os modelos na Gupshup.');

            return self::FAILURE;
        }

        $queue = [];

        foreach ($entries->when($only !== [], fn ($all) => $all->whereIn('key', $only)) as $entry) {
            foreach (array_keys($entry['texts']) as $language) {
                $existing = $result['templates'][$entry['name'] . '|' . $language] ?? null;

                if ($existing) {
                    $this->line("{$entry['name']} ({$language}): já existe ({$existing['status']}), pulado.");

                    continue;
                }

                $queue[] = [$entry, $language, $provider->templateForm($entry, $language)];
            }
        }

        if ($queue === []) {
            $this->info('Nada a criar.');

            return self::SUCCESS;
        }

        if (! $this->option('send')) {
            foreach ($queue as [$entry, $language, $form]) {
                $this->line("\n{$entry['name']} ({$language}) · {$entry['category']}");

                foreach (array_diff_key($form, array_flip(['elementName', 'languageCode', 'category', 'templateType'])) as $field => $value) {
                    $this->line("  {$field}: " . str_replace("\n", ' ⏎ ', $value));
                }
            }

            $this->info("\n" . count($queue) . ' a criar. Rode com --send para enviar à Gupshup (a Meta aprova depois).');

            return self::SUCCESS;
        }

        foreach ($queue as $i => [$entry, $language, $form]) {
            if ($i > 0) {
                Sleep::for(self::SECONDS_BETWEEN_CALLS)->seconds();
            }

            $created = $provider->createTemplate($setting, $form);

            if (! $created['ok']) {
                $this->error("{$entry['name']} ({$language}): " . ($created['error'] ?? 'erro desconhecido') . ' Parei aqui, sem tentar os demais.');

                return self::FAILURE;
            }

            $this->info("{$entry['name']} ({$language}): criado ({$created['status']}).");
        }

        $this->line('Acompanhe a aprovação com: php artisan whatsapp:templates:status');

        return self::SUCCESS;
    }
}
