<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta para o time do SaaS (admin e dono): um provedor de IA parou de
 * atender por falta de crédito ou por chave recusada — ver AiOperationalAlerts.
 * Só o tipo, o provedor e o contexto técnico; nada de paciente.
 */
class AiOperationalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $type,
        public readonly string $provider,
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
        $key  = 'manager_ai.alerts.types.' . $this->type;
        $what = __($key, ['provider' => $this->provider]);
        $what = $what === $key ? $this->provider . ': ' . $this->type : $what;

        $mail = (new MailMessage())
            ->subject(__('manager_ai.alerts.subject', ['what' => $what]))
            ->greeting(__('manager_ai.alerts.greeting', ['name' => $notifiable->name ?? '']))
            ->line($what);

        foreach ($this->context as $name => $value) {
            if ($value !== null && $value !== '') {
                $mail->line($name . ': ' . $value);
            }
        }

        return $mail
            ->action(__('manager_ai.alerts.action'), route('manager.ai-providers.index'))
            ->line(__('manager_ai.alerts.hint'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'provider' => $this->provider, 'context' => $this->context];
    }
}
