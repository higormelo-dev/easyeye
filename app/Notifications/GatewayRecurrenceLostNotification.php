<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Alerta para o time do SaaS (admin, financeiro e dono): o gateway desativou
 * sozinho a recorrência de uma assinatura vigente e a cobrança passou para a
 * renovação local (GatewayRecurrenceLossService). Só dados da assinatura —
 * clínica, plano, gateway e datas.
 */
class GatewayRecurrenceLostNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Subscription $subscription,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s      = $this->subscription;
        $locale = app()->getLocale();
        $params = [
            'entity'  => (string) $s->entity?->name,
            'plan'    => (string) $s->plan?->name,
            'gateway' => strtoupper((string) $s->gateway),
            'until'   => $this->date($s->ends_at, $locale),
            'next'    => $this->date($s->nextBillingDate(), $locale),
        ];

        return (new MailMessage())
            ->subject(__('billing_alerts.recurrence_lost.subject', $params))
            ->greeting(__('billing_alerts.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('billing_alerts.recurrence_lost.line', $params))
            ->line(__('billing_alerts.recurrence_lost.access', $params))
            ->line(__('billing_alerts.recurrence_lost.next', $params))
            ->action(__('billing_alerts.recurrence_lost.action'), route('manager.subscriptions.index', ['status' => 'recurrence_alert']))
            ->line(__('billing_alerts.recurrence_lost.hint'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['subscription_id' => (string) $this->subscription->id];
    }

    private function date(?Carbon $value, string $locale): string
    {
        return $value ? $value->copy()->locale($locale)->isoFormat('L') : '—';
    }
}
