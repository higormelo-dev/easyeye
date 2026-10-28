<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\SubscriptionNoticeService;
use App\Services\SubscriptionService;
use App\Support\Billing\{DunningSchedule, PlanPricing};
use Carbon\CarbonInterface;
use Illuminate\Http\{RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

class SubscriptionExpiredController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionNoticeService $notices,
    ) {
    }

    public function __invoke(Request $request): InertiaResponse|RedirectResponse
    {
        $entityId = session('selected_entity_id');
        $entity   = $entityId ? Entity::find($entityId) : null;
        $level    = $entity ? $this->subscriptionService->accessLevel($entity) : SubscriptionAccessLevel::None;

        // Acesso total: nada a explicar aqui, volta para o dashboard.
        if ($level === SubscriptionAccessLevel::Full) {
            return redirect()->route('panel.dashboard');
        }

        $limited = $level === SubscriptionAccessLevel::Limited;

        // Acesso limitado: a assinatura em atraso que limita. Sem acesso:
        // a última (currentFirst, mesmo desempate de getCurrent) —
        // tentativa de contratação recusada pelo gateway não conta.
        $lastSubscription = match (true) {
            $limited         => $this->subscriptionService->currentAccess($entity)?->load('plan'),
            $entity !== null => Subscription::forEntity($entity->id)->withoutFailedActivations()->with('plan')->currentFirst()->first(),
            default          => null,
        };

        // Valor e link da fatura (página do gateway com nome e CPF/CNPJ do
        // pagador) só para admin, financeiro e dono — os demais perfis são
        // orientados a procurar o administrador (LGPD, minimização).
        $canPay = $entity !== null && $this->notices->canSeeBilling($request->user(), (string) $entity->id);

        // Acesso limitado não troca de plano: só explica e oferece o pagamento.
        $plans = $limited ? collect() : Plan::active()
            ->with(['features' => fn ($q) => $q->orderBy('feature'), 'prices'])
            ->orderBy('sort_order')
            ->get()
            // Sem ciclo à venda (ex.: plano antigo só vitalício) não há o que contratar.
            ->filter(fn (Plan $plan) => $plan->isSellable())
            ->values()
            ->map(fn (Plan $plan) => [
                'id'            => $plan->id,
                'name'          => $plan->name,
                'description'   => $plan->description,
                'is_featured'   => (bool) $plan->is_featured,
                'default_cycle' => $plan->defaultCycle()?->value,
                'prices'        => PlanPricing::cycles($plan),
                'features'      => $plan->features->map(fn ($f) => [
                    'key'     => $f->feature->value,
                    'label'   => $f->formatForDisplay(),
                    'enabled' => $f->feature->isBoolean() ? $f->boolValue() : true,
                ])->values(),
            ]);

        return Inertia::render('Panel/SubscriptionExpired', [
            'mode'   => $limited ? 'limited' : 'blocked',
            'entity' => $entity ? [
                'id'   => (string) $entity->id,
                'name' => $entity->name,
            ] : null,
            'lastSubscription' => $lastSubscription ? [
                'plan_name' => $lastSubscription->plan?->name,
                // Encerrada: quando o acesso acabou de fato (cancelada antes do
                // fim do período, vale a data do cancelamento). Ainda cobrável
                // (aguardando pagamento): não está encerrada — vai o vencimento.
                'ends_at'      => $this->endedAt($lastSubscription)?->toIso8601String(),
                'due_at'       => $this->dueAt($lastSubscription)?->toDateString(),
                'status'       => $lastSubscription->status->value,
                'status_label' => $lastSubscription->isAwaitingFirstPayment()
                    ? __('subscriptions.status_awaiting_first_payment')
                    : $lastSubscription->status->label(),
                // Trial não tem modalidade de cobrança: a tela fala em "teste terminou".
                'was_trial' => $lastSubscription->billing_mode === null,
            ] : null,
            'limited' => $limited && $lastSubscription?->overdueSince() ? [
                // Desde o vencimento real (a régua — bloqueio — conta de overdueSince).
                'days_overdue' => DunningSchedule::daysSince($lastSubscription->unpaidDueDate()),
                'blocked_date' => DunningSchedule::blockedFrom($lastSubscription->overdueSince())->toDateString(),
            ] : null,
            'payment' => $canPay ? $this->payment($lastSubscription) : null,
            'canPay'  => $canPay,
            'plans'   => $plans,
            't'       => trans('subscriptions.expired_page'),
            'urls'    => [
                'logout'    => route('logout'),
                'contact'   => route('site.home') . '#contato',
                'dashboard' => route('panel.dashboard'),
                // Checkout transparente (só para quem paga — canPay).
                'checkout' => $canPay ? route('panel.my-subscription.index') : null,
            ],
        ]);
    }

    /**
     * Fim real do acesso de uma assinatura encerrada; null enquanto ainda é
     * cobrada. Cliente pagante encerrado pela régua: o acesso (total, depois
     * limitado) durou até o encerramento (D+7), não até o vencimento não pago
     * que fica em ends_at.
     */
    private function endedAt(Subscription $subscription): ?CarbonInterface
    {
        if ($subscription->status === SubscriptionStatus::PastDue) {
            return null;
        }

        if ($subscription->status === SubscriptionStatus::Expired
            && $subscription->billing_mode === SubscriptionBillingMode::Gateway
            && $subscription->hasBeenPaid()
            && $subscription->cancelled_at !== null) {
            return $subscription->cancelled_at;
        }

        $end         = $subscription->ends_at ?? $subscription->trial_ends_at;
        $cancelledAt = $subscription->cancelled_at;

        return $cancelledAt !== null && ($end === null || $cancelledAt->lessThan($end)) ? $cancelledAt : $end;
    }

    /** Vencimento da cobrança que segura o acesso (aguardando o pagamento). */
    private function dueAt(Subscription $subscription): ?CarbonInterface
    {
        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return null;
        }

        return $subscription->unpaidDueDate() ?? $subscription->next_billing_at ?? $subscription->ends_at;
    }

    /**
     * Cobrança em aberto com link de pagamento — só de assinatura que ainda é
     * cobrada (aguardando o 1º pagamento ou em atraso). Encerrada não tem o
     * que pagar: o pagamento não religaria o acesso.
     *
     * @return array{url: string, amount: float, due_date: ?string}|null
     */
    private function payment(?Subscription $subscription): ?array
    {
        if (! $subscription
            || $subscription->billing_mode !== SubscriptionBillingMode::Gateway
            || ! in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Active], true)) {
            return null;
        }

        $invoice = $subscription->payableInvoice();

        return $invoice ? [
            'url'      => (string) $invoice->payment_url,
            'amount'   => (float) $invoice->amount,
            'due_date' => $invoice->due_at?->toDateString(),
            // Checkout transparente: a tela pede Pix/boleto/cartão desta
            // fatura em panel.my-subscription.* (liberado no bloqueio).
            'invoice_id' => (string) $invoice->id,
        ] : null;
    }
}
