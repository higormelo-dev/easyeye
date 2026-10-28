<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Notifications\SendsSaasWhatsApp;
use App\Models\Subscription;
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Services\Billing\TrialEndingNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Fim do teste grátis chegando (3 dias antes, 1 dia antes e no dia) — e-mail
 * e WhatsApp aos contatos de cobrança, com o link para contratar dentro do
 * sistema (Minha assinatura). Disparado por `billing:trial-notices`
 * (TrialEndingNoticeService), na fila e no idioma do destinatário. Reconfere
 * na hora do envio: contratou, virou cortesia ou o trial foi estendido — não
 * envia. Só nome da clínica e datas.
 */
class TrialEndingNotification extends Notification implements SendsSaasWhatsApp, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $step,
        public readonly string $trialEndsOn,
        public readonly string $entityName,
        // Dias até o fim, contados no envio do comando (a fila pode atrasar).
        public readonly int $daysLeft = 0,
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
        return app(TrialEndingNoticeService::class)->stillApplies($this->subscription->fresh(), $this->step, $this->trialEndsOn);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = $this->params();

        return (new MailMessage())
            ->subject(__("billing_trial.{$this->step}.subject", $params))
            ->greeting(__('billing_trial.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("billing_trial.{$this->step}.line", $params))
            ->line(__('billing_trial.after'))
            ->action(__('billing_trial.cta'), $this->url())
            ->line(__('billing_trial.paid_note'))
            ->salutation(__('billing_trial.salutation', ['app' => config('app.name')]));
    }

    /** @return array{template: string, values: array<string, string|int>, url: string} */
    public function toSaasWhatsApp(object $notifiable): ?array
    {
        return ['template' => "saas_trial_{$this->step}", 'values' => $this->params(), 'url' => $this->url()];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['subscription_id' => (string) $this->subscription->id, 'step' => $this->step, 'trial_ends_on' => $this->trialEndsOn];
    }

    private function url(): string
    {
        return route('panel.my-subscription.index');
    }

    /** @return array<string, string|int> */
    private function params(): array
    {
        $ends = Carbon::parse($this->trialEndsOn);

        return [
            'app'    => (string) config('app.name'),
            'entity' => $this->entityName,
            'date'   => $ends->copy()->locale(app()->getLocale())->isoFormat('L'),
            'days'   => $this->daysLeft,
        ];
    }
}
