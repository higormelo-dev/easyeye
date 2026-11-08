<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta ao time do SaaS: uma fila parou de andar (queue:check-stalled).
 * NÃO implementa ShouldQueue de propósito: a fila pode ser justamente a
 * parada, então o e-mail sai na hora (notifyNow).
 */
class QueueStalledNotification extends Notification
{
    public function __construct(
        public readonly string $queue,
        public readonly int $minutes,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('queue_alerts.stalled.subject', ['queue' => $this->queue]))
            ->greeting(__('queue_alerts.stalled.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('queue_alerts.stalled.line', ['queue' => $this->queue, 'minutes' => $this->minutes]))
            ->line(__('queue_alerts.stalled.hint'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['queue' => $this->queue, 'minutes' => $this->minutes];
    }
}
