<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta para o time do SaaS (admin e dono): o WhatsApp parou ou vai parar
 * de entregar por um motivo que só uma pessoa resolve — ver WhatsAppAlerts.
 * Só o tipo e o contexto técnico (app, código de erro); nada de paciente.
 */
class WhatsAppOperationalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $type,
        public readonly array $context = [],
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key  = 'whatsapp.alerts.types.' . $this->type;
        $what = __($key);

        $mail = (new MailMessage())
            ->subject(__('whatsapp.alerts.subject', ['what' => $what === $key ? $this->type : $what]))
            ->greeting(__('whatsapp.alerts.greeting', ['name' => $notifiable->name ?? '']))
            ->line($what === $key ? $this->type : $what);

        foreach ($this->context as $name => $value) {
            if ($value !== null && $value !== '') {
                $mail->line($name . ': ' . $value);
            }
        }

        return $mail
            ->action(__('whatsapp.alerts.action'), route('manager.whatsapp.index'))
            ->line(__('whatsapp.alerts.hint'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'context' => $this->context];
    }
}
