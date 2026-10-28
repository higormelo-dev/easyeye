<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\QueriesGatewayRecurrences;
use App\DTOs\Billing\{GatewayCallContext, GatewayRecurrenceChargeDTO};
use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\{Invoice, Payment};
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confere no gateway se a cobrança em atraso já foi paga e, se foi, aplica o
 * pagamento pelo MESMO caminho do webhook (ProcessWebhookEventService::
 * applyNormalized). Cobre o webhook que se perdeu ou atrasou (fila do
 * gateway pausada — https://docs.asaas.com/docs/fila-pausada; reentrega
 * depois de 14 dias perdida).
 *
 * Usado:
 *  - pela régua antes de limitar o acesso (D+3) e de encerrar (D+7):
 *    pago no gateway → aplica e não limita/encerra;
 *  - pelo comando diário billing:reconcile-overdue (teto por execução,
 *    pausa entre consultas e parada no 429 — https://docs.asaas.com/reference/rate-e-quota-limit).
 *
 * Gateway sem consulta (paymentStatusEvent nulo) fica como está.
 */
class GatewayPaymentReconciler
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly ProcessWebhookEventService $webhooks,
        private readonly BillingLogService $billingLog,
    ) {
    }

    /**
     * Faturas em atraso da assinatura com cobrança no gateway: alguma foi
     * paga lá? Aplica as pagas. True = algum pagamento aplicado.
     *
     * @param bool $strict a régua: só "não pago" CONCLUSIVO conta — qualquer
     *                     falha na consulta (timeout, 5xx, chave recusada, 429)
     *                     lança, e a etapa é adiada (nunca encerra sem conferir)
     *
     * @throws GatewayIntegrationException 429 (o chamador decide se para); no modo estrito, qualquer falha
     */
    public function reconcileSubscription(Subscription $subscription, bool $strict = false): bool
    {
        $applied = false;

        foreach ($this->openInvoicesOf($subscription) as $invoice) {
            $applied = $this->reconcileInvoice($invoice, $strict) || $applied;
        }

        // 1ª cobrança da recorrência cujo webhook nunca chegou (fatura sem
        // cobrança ligada): procura na recorrência do gateway.
        if (! $applied && $subscription->hasGatewayRecurrence()) {
            $applied = $this->reconcileRecurrence($subscription, $strict);
        }

        return $applied;
    }

    /**
     * Uma fatura: consulta a cobrança vigente (e as outras ainda valendo).
     * True = pagamento aplicado agora.
     *
     * @param bool $strict qualquer falha na consulta lança (sem resposta conclusiva)
     *
     * @throws GatewayIntegrationException 429; no modo estrito, qualquer falha
     */
    public function reconcileInvoice(Invoice $invoice, bool $strict = false): bool
    {
        if (blank($invoice->gateway_code) || ! $this->registry->has((string) $invoice->gateway_code)
            || in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true)) {
            return false;
        }

        $gateway = $this->registry->get((string) $invoice->gateway_code)
            ->withContext(new GatewayCallContext((string) Str::uuid(), (string) $invoice->entity_id));

        foreach ($invoice->liveChargeIds() as $chargeId) {
            try {
                $event = $gateway->paymentStatusEvent($chargeId);
            } catch (GatewayIntegrationException $e) {
                if ($strict || $e->isRateLimit()) {
                    throw $e;
                }

                Log::warning('Conciliação: falha ao consultar a cobrança no gateway.', ['invoice_id' => $invoice->id, 'charge' => $chargeId, 'error' => $e->getMessage()]);

                continue;
            }

            if ($event === null || ! in_array($event->eventType, ['paid', 'authorized'], true)) {
                continue;
            }

            $outcome = $this->webhooks->applyNormalized($event, 'reconcile');

            $this->billingLog->log(
                level: 'warning',
                message: 'Pagamento encontrado no gateway sem webhook processado — aplicado pela conciliação.',
                context: ['external_charge_id' => $chargeId, 'outcome' => $outcome],
                entityId: (string) $invoice->entity_id,
                invoice: $invoice,
                gatewayCode: $invoice->gateway_code,
            );

            return true;
        }

        return false;
    }

    /** Cobrança paga da recorrência ainda não ligada a nenhuma fatura paga. */
    private function reconcileRecurrence(Subscription $subscription, bool $strict = false): bool
    {
        $gateway = $this->registry->has((string) $subscription->gateway) ? $this->registry->get((string) $subscription->gateway) : null;

        if (! $gateway instanceof QueriesGatewayRecurrences) {
            return false;
        }

        try {
            $recurrence = $gateway->fetchRecurrence((string) $subscription->gateway_subscription_id);
        } catch (GatewayIntegrationException $e) {
            if ($strict || $e->isRateLimit()) {
                throw $e;
            }

            return false;
        }

        // Pagas lá e sem pagamento registrado aqui, dos últimos lookback_days.
        $since       = CarbonImmutable::today()->subDays(max(1, (int) config('billing.reconcile.lookback_days', 60)));
        $paidCharges = collect($recurrence?->charges ?? [])
            ->filter(fn (GatewayRecurrenceChargeDTO $charge) => $charge->status === GatewayRecurrenceChargeDTO::PAID
                && CarbonImmutable::parse($charge->dueDate)->greaterThanOrEqualTo($since))
            ->reject(fn (GatewayRecurrenceChargeDTO $charge) => Payment::query()
                ->where('gateway_code', $subscription->gateway)
                ->where('external_payment_id', $charge->id)
                ->where('status', PaymentStatus::Paid->value)
                ->exists());

        foreach ($paidCharges as $charge) {
            try {
                $event = $gateway->paymentStatusEvent($charge->id);
            } catch (GatewayIntegrationException $e) {
                if ($strict || $e->isRateLimit()) {
                    throw $e;
                }

                continue;
            }

            if ($event !== null && in_array($event->eventType, ['paid', 'authorized'], true)) {
                $this->webhooks->applyNormalized($event, 'reconcile');

                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Invoice> */
    private function openInvoicesOf(Subscription $subscription): Collection
    {
        return $subscription->invoices()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
            ->whereNotNull('external_invoice_id')
            ->where('external_invoice_id', '!=', '')
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Rodada diária: faturas vencidas (até lookback_days) com cobrança no
     * gateway, no máximo max_per_run, com pausa entre as consultas. Para no
     * primeiro 429 (a API pediu para esperar). Começa pelas nunca conferidas
     * e pelas conferidas há mais tempo (invoices.gateway_checked_at) e pula
     * as já conferidas hoje — com mais faturas que o teto, a rodada seguinte
     * continua de onde parou (as recentes não ficam para sempre de fora).
     *
     * @return array{checked: int, applied: int, rate_limited: bool}
     */
    public function run(?int $limit = null, ?int $pauseMs = null, bool $dryRun = false): array
    {
        $limit ??= max(1, (int) config('billing.reconcile.max_per_run', 100));
        $pauseMs ??= max(0, (int) config('billing.reconcile.pause_ms', 300));
        $since = now()->subDays(max(1, (int) config('billing.reconcile.lookback_days', 60)));
        $stats = ['checked' => 0, 'applied' => 0, 'rate_limited' => false];

        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
            ->whereNotNull('external_invoice_id')
            ->where('external_invoice_id', '!=', '')
            ->whereNotNull('gateway_code')
            ->where('due_at', '<', now()->startOfDay())
            ->where('due_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('gateway_checked_at')->orWhere('gateway_checked_at', '<', now()->startOfDay()))
            // Nunca conferidas primeiro (portável: NULLS FIRST não existe em todo banco).
            ->orderByRaw('CASE WHEN gateway_checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('gateway_checked_at')
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        foreach ($invoices as $index => $invoice) {
            if ($dryRun) {
                $stats['checked']++;

                continue;
            }

            if ($index > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }

            try {
                $stats['checked']++;
                $stats['applied'] += $this->reconcileInvoice($invoice) ? 1 : 0;
            } catch (GatewayIntegrationException $e) {
                if ($e->isRateLimit()) {
                    $stats['rate_limited'] = true;

                    break;
                }
            } catch (Throwable $e) {
                report($e);
            }

            // Conferida (mesmo com falha na consulta — a próxima rodada tenta
            // as outras primeiro). A parada no 429 não marca: segue na próxima.
            Invoice::query()->whereKey($invoice->id)->update(['gateway_checked_at' => now()]);
        }

        return $stats;
    }
}
