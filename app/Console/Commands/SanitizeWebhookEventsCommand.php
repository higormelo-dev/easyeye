<?php

namespace App\Console\Commands;

use App\Models\Billing\WebhookEvent;
use App\Support\Billing\PayloadSanitizer;
use Illuminate\Console\Command;

/**
 * One-off (deploy da limpeza de dados pessoais dos webhooks): passa os
 * payloads já gravados em webhook_events pelo PayloadSanitizer::cleanPersonal
 * e os headers pelo PayloadSanitizer::storableHeaders (Basic Auth do
 * Pagar.me, token do Asaas, cookie…) — o mesmo que a ingestão aplica desde
 * então. Idempotente (o já limpo não muda). --dry-run só conta quantos
 * mudariam. Depois de rodar, trocar as credenciais de webhook que vazaram
 * para o banco (docs/billing/checkout-transparente-deploy.md).
 */
class SanitizeWebhookEventsCommand extends Command
{
    protected $signature = 'billing:sanitize-webhook-events
        {--dry-run : Só conta, sem gravar}
        {--chunk=500 : Eventos por lote}';

    protected $description = 'Remove dados pessoais (nome, documento, e-mail, telefone, endereço, cartão) dos payloads e os headers com segredo dos webhooks já gravados';

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $chunk   = max(1, (int) $this->option('chunk'));
        $scanned = 0;
        $changed = 0;
        $headers = 0;

        WebhookEvent::query()->orderBy('id')->chunkById($chunk, function ($events) use ($dryRun, &$scanned, &$changed, &$headers): void {
            foreach ($events as $event) {
                $scanned++;
                $updates = [];

                $payload = $event->payload ?? [];
                $clean   = PayloadSanitizer::cleanPersonal($payload);

                if ($clean !== $payload) {
                    $changed++;
                    $updates['payload'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
                }

                $stored  = $event->headers ?? [];
                $without = PayloadSanitizer::storableHeaders($stored);

                if ($without !== $stored) {
                    $headers++;
                    $updates['headers'] = json_encode($without, JSON_UNESCAPED_UNICODE);
                }

                if ($updates !== [] && ! $dryRun) {
                    // Sem tocar em updated_at/status: só o conteúdo guardado muda.
                    WebhookEvent::query()->whereKey($event->id)->toBase()->update($updates);
                }
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '') . "Webhooks lidos: {$scanned} · com dados pessoais " . ($dryRun ? 'a limpar' : 'limpos') . ": {$changed}"
            . ' · com headers secretos ' . ($dryRun ? 'a limpar' : 'limpos') . ": {$headers}");

        return self::SUCCESS;
    }
}
