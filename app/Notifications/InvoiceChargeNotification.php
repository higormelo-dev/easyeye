<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Notifications\SendsSaasWhatsApp;
use App\Models\Billing\Invoice;
use App\Models\Subscription;
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Services\Billing\InvoiceChargeNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * Cobrança enviada pelo manager à clínica ("Enviar cobrança à clínica"):
 * e-mail e WhatsApp aos contatos de cobrança com o link para pagar DENTRO do
 * sistema (Minha assinatura, já na fatura). Só plano, valor e vencimento.
 * Reconfere na hora do envio: fatura paga/cancelada no meio do caminho não
 * gera aviso.
 */
class InvoiceChargeNotification extends Notification implements SendsSaasWhatsApp, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Subscription $subscription,
        public readonly string $entityName,
        public readonly string $planName,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', ...SaasWhatsAppChannel::channelsFor($notifiable)];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return [SaasWhatsAppChannel::class => 'sync'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $invoice      = $this->invoice->fresh();
        $subscription = $this->subscription->fresh();

        return $invoice !== null && $subscription !== null
            && app(InvoiceChargeNoticeService::class)->canSend($subscription, $invoice);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = $this->params();

        return (new MailMessage())
            ->subject(__('billing_charge_notice.subject', $params))
            ->greeting(__('billing_charge_notice.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__($this->invoice->isPlanChange() ? 'billing_charge_notice.line_plan_change' : 'billing_charge_notice.line', $params))
            ->line(__('billing_charge_notice.how'))
            ->action(__('billing_charge_notice.pay'), $this->url())
            ->line(__('billing_charge_notice.paid_note'))
            ->salutation(__('billing_charge_notice.salutation', ['app' => config('app.name')]));
    }

    public function toSaasWhatsApp(object $notifiable): ?string
    {
        return __($this->invoice->isPlanChange() ? 'billing_charge_notice.whatsapp_plan_change' : 'billing_charge_notice.whatsapp', [...$this->params(), 'url' => $this->url()]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['invoice_id' => (string) $this->invoice->id, 'subscription_id' => (string) $this->subscription->id];
    }

    private function url(): string
    {
        return route('panel.my-subscription.index', ['invoice' => (string) $this->invoice->id]);
    }

    /** @return array<string, string> valores no idioma do envio */
    private function params(): array
    {
        $locale = app()->getLocale();

        return [
            'app'    => (string) config('app.name'),
            'entity' => $this->entityName,
            'plan'   => $this->planName !== '' ? $this->planName : '—',
            'amount' => Number::currency((float) $this->invoice->amount, (string) ($this->invoice->currency ?: 'BRL'), $locale),
            'date'   => $this->invoice->due_at ? $this->invoice->due_at->copy()->locale($locale)->isoFormat('L') : '—',
        ];
    }
}
