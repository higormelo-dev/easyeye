<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Billing\{GatewayCustomer, Invoice};
use App\Models\Subscription;
use App\Services\Billing\GatewayRegistry;
use App\Services\Billing\Gateways\AsaasGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Comando de uma vez (deploy): desliga as notificações do Asaas (e-mail,
 * SMS, WhatsApp, voz, Correios) de todos os clientes do EasyEye já
 * cadastrados lá — notificationDisabled no cadastro do cliente
 * (https://docs.asaas.com/reference/atualizar-cliente-existente). Só a régua
 * do EasyEye fala com a clínica. Idempotente: cliente já marcado
 * (billing_gateway_customers) não é consultado de novo. --dry-run só mostra.
 */
class DisableAsaasNotificationsCommand extends Command
{
    protected $signature = 'billing:asaas-disable-notifications
        {--dry-run : Mostra o que faria, sem alterar nada no Asaas}
        {--pause-ms=300 : Pausa entre clientes (limite da API)}';

    protected $description = 'Desliga as notificações do Asaas para os clientes já cadastrados (uma vez, no deploy)';

    public function handle(GatewayRegistry $registry): int
    {
        if (! $registry->has('asaas') || ! ($gateway = $registry->get('asaas')) instanceof AsaasGateway) {
            $this->warn(__('billing_console.asaas_notifications.no_gateway'));

            return self::SUCCESS;
        }

        $dryRun    = (bool) $this->option('dry-run');
        $pause     = max(0, (int) $this->option('pause-ms'));
        $customers = $this->customers();
        $counts    = ['already' => 0, 'disabled' => 0, 'failed' => 0, 'not_found' => 0];
        $rows      = [];

        foreach ($customers as $index => [$customerId, $entityId]) {
            if (! $dryRun && $index > 0 && $pause > 0) {
                usleep($pause * 1000);
            }

            $result = $dryRun && GatewayCustomer::notificationsDisabled('asaas', $customerId)
                ? 'already'
                : $gateway->ensureNotificationsDisabled($customerId, null, $entityId, $dryRun);

            $counts[$result] = ($counts[$result] ?? 0) + 1;
            $rows[]          = [$customerId, __("billing_console.asaas_notifications.result_{$result}" . ($dryRun && $result === 'disabled' ? '_dry' : ''))];
        }

        $this->table([__('billing_console.asaas_notifications.col_customer'), __('billing_console.asaas_notifications.col_result')], $rows);

        $this->info(__($dryRun ? 'billing_console.asaas_notifications.dry_run_done' : 'billing_console.asaas_notifications.done', [
            'total'    => count($rows),
            'disabled' => $counts['disabled'],
            'already'  => $counts['already'],
            'failed'   => $counts['failed'] + $counts['not_found'],
        ]));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Clientes do Asaas conhecidos: das assinaturas e das faturas de pacote
     * de IA (metadata.gateway_customer_id).
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function customers(): array
    {
        /** @var Collection<string, ?string> $found */
        $found = collect();

        Subscription::query()
            ->withTrashed()
            ->where('gateway', 'asaas')
            ->whereNotNull('gateway_customer_id')
            ->orderBy('created_at')
            ->get(['gateway_customer_id', 'entity_id'])
            ->each(function (Subscription $s) use ($found): void {
                $found->put((string) $s->gateway_customer_id, $found->get((string) $s->gateway_customer_id) ?? (string) $s->entity_id);
            });

        Invoice::query()
            ->where('gateway_code', 'asaas')
            ->whereNotNull('metadata')
            ->get(['metadata', 'entity_id'])
            ->each(function (Invoice $invoice) use ($found): void {
                $id = data_get($invoice->metadata, 'gateway_customer_id');

                if (is_string($id) && $id !== '' && ! $found->has($id)) {
                    $found->put($id, (string) $invoice->entity_id);
                }
            });

        return $found
            ->filter(fn ($entity, $id) => preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $id) === 1)
            ->map(fn ($entity, $id) => [(string) $id, $entity])
            ->values()
            ->all();
    }
}
