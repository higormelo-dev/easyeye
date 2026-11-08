<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\GupshupSelfServeProvider;
use Illuminate\Console\Command;

/**
 * Confere, só em leitura, os modelos do catálogo (whatsapp.templates × whatsapp.template_languages)
 * contra os do app GLOBAL na Gupshup: o que falta criar, o que está pendente ou foi recusado
 * (e o motivo da Meta). Não cria nem altera nada — o envio só usa os APPROVED.
 */
class WhatsAppTemplatesStatusCommand extends Command
{
    protected $signature = 'whatsapp:templates:status';

    protected $description = 'Compara os modelos do catálogo com os do app na Gupshup (só leitura): o que falta, está pendente ou foi recusado';

    public function handle(WhatsAppProvider $provider): int
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

        $result = $provider->templateStatuses($setting);

        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Não foi possível ler os modelos na Gupshup.');

            return self::FAILURE;
        }

        $remote = $result['templates'] ?? [];
        $rows   = [];
        $notes  = [];
        $known  = [];

        foreach ((array) config('whatsapp.templates') as $template) {
            foreach ((array) config('whatsapp.template_languages') as $language) {
                $key     = $template['name'] . '|' . $language;
                $found   = $remote[$key] ?? null;
                $known[] = $key;
                $rows[]  = [$template['name'], $language, $template['category'], $found['status'] ?? 'AUSENTE'];

                if ($found && $found['status'] !== 'APPROVED' && $found['reason'] !== '') {
                    $notes[] = "{$template['name']} ({$language}): {$found['reason']}";
                }
            }
        }

        $this->table(['Modelo', 'Idioma', 'Categoria', 'Na Gupshup'], $rows);

        $counts = array_count_values(array_column($rows, 3));
        ksort($counts);
        $this->line('Resumo: ' . implode(', ', array_map(fn (string $status, int $n) => "{$status} {$n}", array_keys($counts), $counts)) . '.');

        foreach ($notes as $note) {
            $this->warn($note);
        }

        $extras = array_diff_key($remote, array_flip($known));

        if ($extras !== []) {
            $this->line('No app, mas fora do catálogo ou dos idiomas configurados:');

            foreach ($extras as $key => $extra) {
                [$name, $language] = explode('|', $key, 2);
                $this->line("  {$name} ({$language}): {$extra['status']}");
            }
        }

        return self::SUCCESS;
    }
}
