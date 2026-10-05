<?php

declare(strict_types=1);

namespace App\Events\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Broadcasting\{InteractsWithSockets, PrivateChannel};
use Illuminate\Contracts\Broadcasting\{ShouldBroadcastNow, ShouldRescue};
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fatura da assinatura paga (webhook, cartão aprovado no checkout ou
 * renovação no cartão salvo) — empurrada para o navegador via Reverb no canal
 * privado da clínica, para a tela do Pix/boleto liberar sozinha, sem polling.
 *
 * - ShouldDispatchAfterCommit: só anuncia o pagamento gravado (rollback não
 *   avisa nada);
 * - ShouldBroadcastNow: já roda no job do webhook ou na requisição do cartão;
 * - ShouldRescue: Reverb fora do ar vira log, nunca derruba o pagamento.
 *
 * Só ids, status, valor e datas — nada de pagador, cartão ou paciente.
 */
class InvoicePaid implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $entityId,
        public readonly string $invoiceId,
        public readonly ?string $subscriptionId,
        public readonly float $amount,
        public readonly ?string $paidAt,
        public readonly ?string $subscriptionStatus = null,
        public readonly ?string $endsAt = null,
    ) {
    }

    public static function channelName(string $entityId): string
    {
        return "billing.{$entityId}";
    }

    public static function fromInvoice(Invoice $invoice, ?string $subscriptionStatus = null, ?string $endsAt = null): self
    {
        return new self(
            entityId: (string) $invoice->entity_id,
            invoiceId: (string) $invoice->id,
            subscriptionId: $invoice->subscription_id ? (string) $invoice->subscription_id : null,
            amount: (float) $invoice->amount,
            paidAt: $invoice->paid_at?->toIso8601String(),
            subscriptionStatus: $subscriptionStatus,
            endsAt: $endsAt,
        );
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->entityId))];
    }

    public function broadcastAs(): string
    {
        return 'invoice.paid';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'invoice_id'          => $this->invoiceId,
            'subscription_id'     => $this->subscriptionId,
            'status'              => 'paid',
            'amount'              => $this->amount,
            'paid_at'             => $this->paidAt,
            'subscription_status' => $this->subscriptionStatus,
            'ends_at'             => $this->endsAt,
        ];
    }
}
