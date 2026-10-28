<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionAccessLevel;
use App\Models\{EntityUser, Subscription, User};
use App\Support\Billing\DunningSchedule;

/**
 * Aviso da situação da assinatura no topo do painel da clínica (prop
 * compartilhada `subscriptionBanner`, ver HandleInertiaRequests):
 *
 *  - first_payment_pending: contratação aguardando o 1º pagamento (acesso
 *    até o fim do dia do vencimento);
 *  - overdue: cliente pagante em atraso, ainda com acesso total;
 *  - limited: em atraso com acesso limitado (IA e financeiro bloqueados);
 *  - trial_ending: teste grátis terminando em até TRIAL_WARNING_DAYS dias.
 *
 * Os avisos de pagamento não podem ser fechados. Usa a mesma regra de acesso
 * do CheckSubscription (Subscription::accessLevel). Datas puras (Y-m-d) e
 * valores crus — a formatação fica no navegador, no idioma do usuário. Sem
 * dado de paciente.
 *
 * Valor e link da fatura (a página do gateway mostra nome e CPF/CNPJ do
 * pagador) só para os contatos de cobrança da empresa — admin, financeiro e
 * dono, os mesmos da régua (EntityUser::billingContacts). Os demais perfis
 * veem o aviso sem valor nem link (`can_pay` = false) e a orientação de
 * procurar o administrador.
 */
class SubscriptionNoticeService
{
    public const TRIAL_WARNING_DAYS = 3;

    /**
     * @param Subscription|null $subscription a que dá acesso à empresa hoje
     *                                        (SubscriptionService::currentAccessFor)
     * @param User|null         $viewer       quem vê o aviso (sem usuário: sem valor nem link)
     *
     * @return array<string, mixed>|null
     */
    public function banner(?Subscription $subscription, ?User $viewer = null): ?array
    {
        if (! $subscription) {
            return null;
        }

        $notice = $this->notice($subscription);

        if (! $notice) {
            return null;
        }

        if (array_key_exists('payment_url', $notice)) {
            $canPay     = $this->canSeeBilling($viewer, (string) $subscription->entity_id);
            $paymentUrl = $canPay ? $notice['payment_url'] : null;
            $notice     = [
                ...$notice,
                'can_pay'     => $canPay,
                'payment_url' => $paymentUrl,
                'amount'      => $canPay ? $notice['amount'] : null,
                // Sem o link da cobrança (gateway sem link, ou o webhook ainda
                // não chegou): o caminho é falar com a equipe, como no e-mail.
                'contact_url' => $canPay && blank($paymentUrl) ? route('site.home') . '#contato' : null,
                // Checkout transparente: fatura a pagar na tela "Minha assinatura".
                'invoice_id'   => $canPay ? ($notice['invoice_id'] ?? null) : null,
                'checkout_url' => $canPay ? route('panel.my-subscription.index') : null,
            ];
        }

        // Trial terminando: quem paga contrata dentro do sistema (Minha assinatura).
        if (($notice['kind'] ?? null) === 'trial_ending' && $this->canSeeBilling($viewer, (string) $subscription->entity_id)) {
            $notice['checkout_url'] = route('panel.my-subscription.index');
        }

        return [...$notice, 't' => trans('subscriptions.banner')];
    }

    /**
     * O usuário é contato de cobrança da empresa (admin, financeiro ou
     * dono, vínculo ativo)? Só ele vê valor e link da fatura.
     */
    public function canSeeBilling(?User $user, string $entityId): bool
    {
        return $user !== null && $entityId !== ''
            && EntityUser::query()
                ->where('user_id', $user->id)
                ->where('entity_id', $entityId)
                ->billingContacts()
                ->exists();
    }

    /** @return array<string, mixed>|null */
    private function notice(Subscription $subscription): ?array
    {
        if ($subscription->isInDunning()) {
            $since   = $subscription->overdueSince();
            $level   = $subscription->accessLevel();
            $invoice = $subscription->payableInvoice();

            if ($since === null || ! $level->hasAccess()) {
                return null;
            }

            // Vencimento real da cobrança (na linha conciliada do código
            // anterior a régua — datas de bloqueio — conta da conciliação).
            $due = $subscription->unpaidDueDate() ?? $since;

            return [
                'kind'         => $level === SubscriptionAccessLevel::Limited ? 'limited' : 'overdue',
                'level'        => $level->value,
                'dismissible'  => false,
                'due_date'     => $due->toDateString(),
                'days_overdue' => DunningSchedule::daysSince($due),
                'limited_date' => DunningSchedule::limitedFrom($since)->toDateString(),
                'blocked_date' => DunningSchedule::blockedFrom($since)->toDateString(),
                'payment_url'  => $invoice?->payment_url,
                'amount'       => $invoice ? (float) $invoice->amount : $subscription->recurringAmount(),
                'invoice_id'   => $invoice ? (string) $invoice->id : null,
            ];
        }

        $firstChargeEnd = $subscription->firstChargeAccessEndsAt();

        if ($firstChargeEnd !== null) {
            $invoice = $subscription->payableInvoice();

            return [
                'kind'        => 'first_payment_pending',
                'level'       => SubscriptionAccessLevel::Full->value,
                'dismissible' => false,
                'due_date'    => $firstChargeEnd->toDateString(),
                'payment_url' => $invoice?->payment_url,
                'amount'      => $invoice ? (float) $invoice->amount : $subscription->recurringAmount(),
                'invoice_id'  => $invoice ? (string) $invoice->id : null,
            ];
        }

        if ($subscription->isOnTrial()) {
            $daysLeft = DunningSchedule::daysSince(now(), $subscription->trial_ends_at);

            if ($daysLeft > self::TRIAL_WARNING_DAYS) {
                return null;
            }

            return [
                'kind'        => 'trial_ending',
                'level'       => SubscriptionAccessLevel::Full->value,
                'dismissible' => true,
                'due_date'    => $subscription->trial_ends_at->toDateString(),
                'days_left'   => $daysLeft,
                'contact_url' => route('site.home') . '#contato',
            ];
        }

        return null;
    }
}
