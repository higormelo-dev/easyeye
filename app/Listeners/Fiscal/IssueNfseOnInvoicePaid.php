<?php

declare(strict_types=1);

namespace App\Listeners\Fiscal;

use App\Events\Billing\InvoicePaid;
use App\Exceptions\Fiscal\NfseIssueRejectedException;
use App\Models\Billing\Invoice;
use App\Models\Entity;
use App\Services\Billing\BillingLogService;
use App\Services\Fiscal\NfseIssueService;
use App\Support\Fiscal\NfseSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fatura paga → cria a NFS-e (emissão automática, quando ligada no Manager → Nota fiscal). Cobre assinatura (ciclo e troca
 * de plano) e pacote de créditos de IA: todos disparam `InvoicePaid`.
 *
 * Roda na FILA e depois do commit do pagamento (o evento é ShouldDispatchAfterCommit), e NUNCA derruba o billing:
 *  - guarda que barra (documento inválido, prestador incompleto, token ausente…) vira registro no log de cobrança e a
 *    fatura aparece em "Faturas pagas sem nota" no Manager — nada de barulho por fatura;
 *  - qualquer outra falha é só reportada (a emissão em lote refaz o que ficou para trás).
 * Clínica de teste/inativa não é um problema aqui: só `is_client` emite (a entidade do próprio SaaS nunca é tomadora).
 */
class IssueNfseOnInvoicePaid implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public function viaQueue(): string
    {
        return (string) config('fiscal.queue', 'default');
    }

    public function handle(InvoicePaid $event): void
    {
        if (! app(NfseSettings::class)->autoIssue()) {
            return;
        }

        $invoice = Invoice::query()->find($event->invoiceId);

        if ($invoice === null || ! Entity::withTrashed()->whereKey($invoice->entity_id)->where('is_client', true)->exists()) {
            return;
        }

        try {
            app(NfseIssueService::class)->issue($invoice, NfseIssueService::SOURCE_AUTO);
        } catch (NfseIssueRejectedException $e) {
            Log::info('nfse.auto_issue.rejected', ['invoice_id' => $invoice->id, 'reason' => $e->reason]);

            app(BillingLogService::class)->log(
                level: 'warning',
                message: 'NFS-e não emitida automaticamente: ' . $e->getMessage(),
                context: ['reason' => $e->reason] + $e->context,
                entityId: (string) $invoice->entity_id,
                invoice: $invoice,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
