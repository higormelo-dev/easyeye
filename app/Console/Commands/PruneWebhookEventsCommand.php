<?php

namespace App\Console\Commands;

use App\Enums\Billing\WebhookEventStatus;
use App\Models\Billing\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Retenção de webhook_events: apaga os PROCESSADOS há mais de
 * billing.webhooks.retention_days dias (BILLING_WEBHOOK_RETENTION_DAYS,
 * padrão 90). Com falha, recebidos, em processamento ou ignorados ficam —
 * são os que alguém ainda precisa olhar ou reprocessar.
 *
 * Idempotência de reentrega: as chaves únicas (gateway + id do evento /
 * hash do corpo) valem enquanto o evento existe; um reenvio depois da
 * retenção cai no Payment já pago (o processamento é idempotente por
 * pagamento). billing_logs.webhook_event_id vira nulo (nullOnDelete).
 */
class PruneWebhookEventsCommand extends Command
{
    protected $signature = 'billing:prune-webhook-events
        {--days= : Dias de retenção (padrão: billing.webhooks.retention_days)}
        {--dry-run : Só conta, sem apagar}';

    protected $description = 'Apaga webhooks de billing processados há mais de N dias (mantém os com falha ou não processados)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('billing.webhooks.retention_days', 90));

        if ($days < 1) {
            $this->error('Retenção inválida: informe ao menos 1 dia.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $query  = WebhookEvent::query()
            ->where('status', WebhookEventStatus::Processed->value)
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Webhooks processados antes de {$cutoff->toDateString()} que seriam apagados: {$query->count()}");

            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $ids = (clone $query)->limit(1000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += WebhookEvent::query()->whereKey($ids)->delete();
        } while (true);

        $this->info("Webhooks processados apagados (antes de {$cutoff->toDateString()}): {$deleted}");

        return self::SUCCESS;
    }
}
