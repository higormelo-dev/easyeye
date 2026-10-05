<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Notifications\SendsSaasWhatsApp;
use App\Enums\Billing\DunningStep;
use App\Models\Subscription;
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Services\Billing\DunningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\{Carbon, Number};

/**
 * E-mail da régua de cobrança para a clínica (admin, financeiro e dono):
 * lembrete antes do vencimento, pagamento não identificado (com o link —
 * a "nova tentativa" do boleto/Pix), acesso limitado e encerramento. Também
 * pelo WhatsApp (instância global do SaaS — SaasWhatsAppChannel), para o
 * contato com telefone verificado: texto curto com o link para pagar DENTRO
 * do sistema (Minha assinatura), nunca o do gateway.
 *
 * Disparado pelo comando `billing:dunning` (DunningService), na fila e no
 * idioma do destinatário (->locale()). Antes do envio a situação é
 * reconferida (shouldSend): pagou ou mudou de etapa enquanto estava na
 * fila, não envia. Só dados da assinatura — nunca dado de paciente.
 */
class SubscriptionDunningNotification extends Notification implements SendsSaasWhatsApp, ShouldQueue
{
    use Queueable;

    /**
     * @param array{entity: string, due_date?: ?string, amount: ?float, payment_url: ?string, invoice_id?: ?string, limited_on: ?string, blocked_on: ?string, charges?: string} $context
     */
    public function __construct(
        public readonly Subscription $subscription,
        public readonly DunningStep $step,
        public readonly string $dueOn,
        public readonly array $context,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', ...SaasWhatsAppChannel::channelsFor($notifiable)];
    }

    /**
     * O canal do WhatsApp só agenda o job dele (fila própria, horário
     * comercial): roda na hora, sem um job de notificação a mais.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [SaasWhatsAppChannel::class => 'sync'];
    }

    /** Reconfere a situação na hora do envio (a fila pode atrasar). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(DunningService::class)->stillApplies($this->subscription->fresh(), $this->step, $this->dueOn);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key    = "billing_dunning.{$this->step->value}";
        $params = $this->params();
        // Cobrança emitida: paga DENTRO do sistema (fatura em Minha
        // assinatura), nunca no link do gateway — o checkout transparente
        // abre a mesma fatura. Sem cobrança emitida segue o contato.
        $url = filled($this->context['payment_url'] ?? null) && filled($this->context['invoice_id'] ?? null)
            ? route('panel.my-subscription.index', ['invoice' => (string) $this->context['invoice_id']])
            : ($this->context['payment_url'] ?? null);

        $mail = (new MailMessage())
            ->subject(__("{$key}.subject", $params))
            ->greeting(__('billing_dunning.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("{$key}.line", $params));

        match ($this->step) {
            // Sem link (cobrança ainda não emitida): o painel não mostra a
            // cobrança antes do vencimento — o caminho é o contato.
            // Cartão salvo: cobrado sozinho no vencimento — sem "link
            // indisponível" nem botão de pagar; troca de cartão pelo painel.
            DunningStep::Reminder => is_array($this->context['card'] ?? null)
                ? $this->cardReminder($mail, $params)
                : ($url
                    ? $mail->action(__('billing_dunning.pay_now'), $url)
                    : $mail->line(__("{$key}.no_link"))->action(__('billing_dunning.contact'), $this->contactUrl())),
            DunningStep::Overdue => $this->payOrContact(
                $mail->line(__("{$key}.deadline", $params)),
                $url,
                __("{$key}.retry"),
            ),
            DunningStep::FirstChargeOverdue => $this->payOrContact($mail->line(__("{$key}.retry", $params)), $url),
            DunningStep::Limited            => $this->payOrContact($mail->line(__("{$key}.deadline", $params)), $url),
            DunningStep::Terminated,
            DunningStep::FirstChargeTerminated => $mail
                ->line(__('billing_dunning.charges.' . ($this->context['charges'] ?? 'none'), $params))
                ->line(__("{$key}.next", $params))
                ->action(__('billing_dunning.contact'), $this->contactUrl()),
        };

        // Quem já pagou: sem "desconsidere" — depois do vencimento o bloqueio
        // da régua não espera a confirmação, que volta o acesso sozinha. No
        // cartão salvo não há o que pagar antes do vencimento.
        if (! $this->step->terminates() && ! ($this->step === DunningStep::Reminder && is_array($this->context['card'] ?? null))) {
            $mail->line(__($this->step === DunningStep::Reminder ? 'billing_dunning.paid_note' : 'billing_dunning.paid_note_overdue'));
        }

        return $mail->salutation(__('billing_dunning.salutation', ['app' => config('app.name')]));
    }

    /**
     * WhatsApp: o essencial do e-mail (etapa, valor, datas) e o link para
     * pagar dentro do sistema — a fatura em Minha assinatura; no
     * encerramento, Minha assinatura (contratar de novo).
     */
    public function toSaasWhatsApp(object $notifiable): ?string
    {
        $params = $this->params();
        $step   = $this->step;
        $key    = match (true) {
            $step === DunningStep::Reminder && is_array($this->context['card'] ?? null) => 'reminder_card',
            default                                                                     => $step->value,
        };
        $invoiceId = $step->terminates() ? null : ($this->context['invoice_id'] ?? null);

        return __("billing_dunning.whatsapp.{$key}", [
            ...$params,
            'last4' => (string) data_get($this->context, 'card.last4', ''),
            'url'   => route('panel.my-subscription.index', array_filter(['invoice' => $invoiceId])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => (string) $this->subscription->id,
            'step'            => $this->step->value,
            'due_on'          => $this->dueOn,
        ];
    }

    /**
     * Com o link da cobrança daquele vencimento: "Pagar agora". Sem link,
     * nada de botão de pagamento (a tela do painel não deixa pagar): diz que o
     * link não está disponível e aponta para o contato — sem prometer e-mail
     * do gateway (nem todos mandam ao pagador).
     */
    private function payOrContact(MailMessage $mail, ?string $url, ?string $intro = null): MailMessage
    {
        if ($url) {
            return ($intro ? $mail->line($intro) : $mail)->action(__('billing_dunning.pay_now'), $url);
        }

        return $mail->line(__('billing_dunning.no_link'))->action(__('billing_dunning.contact'), $this->contactUrl());
    }

    /** Lembrete da renovação no cartão salvo ("vamos cobrar no cartão final 1234 em 10/11"). */
    private function cardReminder(MailMessage $mail, array $params): MailMessage
    {
        $card = (array) $this->context['card'];

        $mail->line(__('billing_dunning.reminder.card', [
            ...$params,
            'brand' => ucfirst((string) ($card['brand'] ?? '')),
            'last4' => (string) ($card['last4'] ?? ''),
        ]));

        if (! empty($card['in_full'])) {
            $mail->line(__('billing_dunning.reminder.card_in_full', $params));
        }

        return $mail->line(__('billing_dunning.reminder.card_change'))
            ->action(__('billing_dunning.manage_subscription'), route('panel.my-subscription.index'));
    }

    private function contactUrl(): string
    {
        return route('site.home') . '#contato';
    }

    /** Valores no idioma do envio (o Laravel aplica o locale da notificação). */
    private function params(): array
    {
        $locale = app()->getLocale();
        $amount = $this->context['amount'] ?? null;

        return [
            'app'    => config('app.name'),
            'entity' => $this->context['entity'] ?? '',
            'amount' => $amount !== null ? Number::currency((float) $amount, 'BRL', $locale) : '—',
            // Vencimento real da cobrança em atraso; sem ele, o da etapa.
            'date'           => $this->date($this->context['due_date'] ?? $this->dueOn, $locale),
            'limited_date'   => $this->date($this->context['limited_on'] ?? null, $locale),
            'blocked_date'   => $this->date($this->context['blocked_on'] ?? null, $locale),
            'terminate_date' => $this->date($this->context['blocked_on'] ?? null, $locale),
        ];
    }

    private function date(?string $value, string $locale): string
    {
        return $value ? Carbon::parse($value)->locale($locale)->isoFormat('L') : '—';
    }
}
