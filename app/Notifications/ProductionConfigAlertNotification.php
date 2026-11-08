<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta ao time do SaaS: a configuração de produção tem itens simulados ou desligados
 * (ops:check-production-config). Sai na hora, sem passar pela fila: se a fila estiver parada, é o
 * tipo de problema que este e-mail existe para contar.
 */
class ProductionConfigAlertNotification extends Notification
{
    /** @param list<string> $codes achados novos (ProductionConfigAudit) */
    public function __construct(public readonly array $codes)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject(__('ops_alerts.config.subject', ['count' => count($this->codes)]))
            ->greeting(__('ops_alerts.config.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('ops_alerts.config.intro'));

        foreach ($this->codes as $code) {
            $mail->line('• ' . __("ops_alerts.findings.{$code}"));
        }

        return $mail->line(__('ops_alerts.config.hint'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['codes' => $this->codes];
    }
}
