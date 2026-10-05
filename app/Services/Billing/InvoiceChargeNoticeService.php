<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\Billing\CheckoutException;
use App\Models\Billing\{Invoice, SubscriptionChange};
use App\Models\{Subscription, User};
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Notifications\InvoiceChargeNotification;
use App\Support\Billing\NoticeLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Throwable;

/**
 * "Enviar cobrança à clínica" (manager): e-mail + WhatsApp (instância global
 * do SaaS) aos contatos de cobrança da clínica — admin, financeiro e dono, os
 * mesmos da régua — com o link para pagar DENTRO do sistema
 * (/panel/my-subscription?invoice=<id>), nunca o link do gateway. Serve para
 * a diferença do upgrade feito pelo manager e para qualquer fatura em aberto
 * da assinatura vigente.
 *
 *  - Só fatura que a clínica consegue pagar agora em Minha assinatura
 *    (CheckoutService::canPayInPanel); paga/cancelada/substituída: 409.
 *  - No máximo um envio por fatura a cada
 *    billing.notices.charge_notice_cooldown_minutes (padrão 10): 429.
 *  - Auditoria: SubscriptionChange `charge_notice_sent` (quem, quando,
 *    canais, quantos contatos) — aparece no histórico da assinatura.
 *  - Mensagem mínima: plano, valor e vencimento.
 */
class InvoiceChargeNoticeService
{
    public const CHANGE_TYPE = 'charge_notice_sent';

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly DunningService $dunning,
    ) {
    }

    public function canSend(Subscription $subscription, Invoice $invoice): bool
    {
        return $this->checkout->canPayInPanel($subscription, $invoice);
    }

    /** Último envio desta fatura (null = nunca enviada). */
    public function lastNotice(Invoice $invoice): ?SubscriptionChange
    {
        return SubscriptionChange::query()
            ->where('subscription_id', $invoice->subscription_id)
            ->where('change_type', self::CHANGE_TYPE)
            ->where('metadata->invoice_id', (string) $invoice->id)
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array{recipients: int, whatsapp: int, channels: list<string>, sent_at: string}
     *
     * @throws CheckoutException
     */
    public function send(Subscription $subscription, Invoice $invoice, User $by): array
    {
        $lock = Cache::lock('billing:charge-notice:' . $invoice->id, 15);

        if (! $lock->get()) {
            throw new CheckoutException('busy', __('manager_subscriptions.charge_notice.errors.busy'), 409);
        }

        try {
            return $this->sendLocked($subscription->fresh(['entity', 'plan']), $invoice->fresh('plan'), $by);
        } finally {
            $lock->release();
        }
    }

    /** @return array{recipients: int, whatsapp: int, channels: list<string>, sent_at: string} */
    private function sendLocked(Subscription $subscription, Invoice $invoice, User $by): array
    {
        if (! $this->canSend($subscription, $invoice)) {
            throw new CheckoutException('invoice_not_payable', __('manager_subscriptions.charge_notice.errors.not_payable'), 409);
        }

        $cooldown = max(1, (int) config('billing.notices.charge_notice_cooldown_minutes', 10));
        $last     = $this->lastNotice($invoice);

        if ($last !== null && $last->created_at !== null && $last->created_at->greaterThan(now()->subMinutes($cooldown))) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($last->created_at->copy()->addMinutes($cooldown)) / 60));

            throw new CheckoutException('rate_limited', trans_choice('manager_subscriptions.charge_notice.errors.cooldown', $minutes, ['minutes' => $minutes]), 429);
        }

        $recipients = $this->dunning->recipients((string) $subscription->entity_id);

        if ($recipients->isEmpty()) {
            throw new CheckoutException('no_recipients', __('manager_subscriptions.charge_notice.errors.no_recipients'), 422);
        }

        $whatsapp = $recipients->filter(fn (User $user) => SaasWhatsAppChannel::reachable($user))->count();
        $channels = $whatsapp > 0 ? ['mail', 'whatsapp'] : ['mail'];
        $now      = CarbonImmutable::now();

        // Registro antes do envio: dois cliques não enviam duas vezes.
        DB::transaction(fn () => SubscriptionChange::query()->create([
            'entity_id'             => $subscription->entity_id,
            'subscription_id'       => $subscription->id,
            'previous_plan_id'      => $subscription->plan_id,
            'new_plan_id'           => $subscription->plan_id,
            'previous_gateway_code' => $subscription->gateway,
            'new_gateway_code'      => $subscription->gateway,
            'change_type'           => self::CHANGE_TYPE,
            'reason'                => 'charge_notice',
            'changed_by'            => $by->id,
            'effective_at'          => $now,
            'correlation_id'        => (string) Str::uuid(),
            'metadata'              => [
                'invoice_id'          => (string) $invoice->id,
                'reference'           => $invoice->reference,
                'amount'              => (float) $invoice->amount,
                'billing_reason'      => $invoice->billing_reason,
                'channels'            => $channels,
                'recipients'          => $recipients->count(),
                'whatsapp_recipients' => $whatsapp,
                'source'              => 'manager',
            ],
        ]));

        $planName = (string) (data_get($invoice->metadata, 'plan_change.plan_name') ?? $invoice->plan?->name ?? $subscription->plan?->name ?? '');

        foreach ($recipients as $user) {
            try {
                $user->notify(
                    (new InvoiceChargeNotification($invoice, $subscription, (string) $subscription->entity?->name, $planName))
                        ->locale(NoticeLocale::for($user, $subscription->entity)),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'recipients' => $recipients->count(),
            'whatsapp'   => $whatsapp,
            'channels'   => $channels,
            'sent_at'    => $now->toIso8601String(),
        ];
    }
}
