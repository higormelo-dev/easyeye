<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta operacional de gateway para o time do SaaS (admin, financeiro e
 * dono) — GatewayAlertService: chave recusada (401/403), chave do ambiente
 * errado, chave perto de expirar/desabilitada pelo gateway, ajuste manual
 * pendente na recorrência, estorno sem saldo de créditos. Só dados
 * operacionais (gateway, códigos e valores) — nada de cartão nem de paciente.
 *
 * $kind escolhe os textos em billing_alerts.gateway.{kind}.
 */
class GatewayOperationalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, scalar|null> $params */
    public function __construct(
        public readonly string $kind,
        public readonly string $gateway,
        public readonly array $params = [],
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = ['gateway' => strtoupper($this->gateway), ...array_map(fn ($v) => (string) $v, $this->params)];
        $base   = "billing_alerts.gateway.{$this->kind}";

        return (new MailMessage())
            ->subject(__("{$base}.subject", $params))
            ->greeting(__('billing_alerts.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("{$base}.line", $params))
            ->line(__("{$base}.hint", $params))
            ->action(__('billing_alerts.gateway.action'), route('manager.gateways.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'gateway' => $this->gateway, 'params' => $this->params];
    }
}
