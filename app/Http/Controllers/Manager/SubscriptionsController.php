<?php

namespace App\Http\Controllers\Manager;

use App\DTOs\Billing\SubscriptionTerms;
use App\Enums\Billing\{CancellationReason, DunningStep, GatewayCode, InvoiceStatus};
use App\Enums\{BillingCycle, SaasRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\{BillingException, CheckoutException, SubscriptionSupersededException};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\DestructiveActionRequest;
use App\Http\Requests\Manager\Subscriptions\{ExtendSubscriptionRequest, RefundPaymentRequest, StoreSubscriptionRequest, UpdateSubscriptionTermsRequest};
use App\Models\Billing\{BillingRefund, BillingRetrySchedule, Invoice, Payment, SubscriptionChange};
use App\Models\{Entity, Plan, PlanPrice, Subscription, SubscriptionSetting, User};
use App\Services\Audit\AuditLogger;
use App\Services\Billing\{BillingCancellationService, BillingSubscriptionOrchestrator, GatewayRecurrenceLossService, InvoiceChargeNoticeService, PlanChangeService, RefundService, SubscriptionCycleService, SubscriptionManagementService};
use App\Services\SubscriptionService;
use App\Support\Billing\{DunningSchedule, PlanPricing};
use App\Support\BrazilianFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

class SubscriptionsController extends Controller
{
    /** Filtro de modalidade da listagem (o que o manager enxerga, não a coluna crua). */
    private const MODALITIES = ['trial', 'gateway', 'complimentary'];

    private const STATUS_FILTERS = ['accessible', 'no_access', 'trial', 'active', 'past_due', 'awaiting_payment', 'needs_review', 'recurrence_alert', 'expired', 'cancelled'];

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionManagementService $management,
        private readonly BillingSubscriptionOrchestrator $billingSubscriptionOrchestrator,
        private readonly BillingCancellationService $billingCancellationService,
        private readonly AuditLogger $audit,
        private readonly PlanChangeService $planChanges,
        private readonly SubscriptionCycleService $cycles,
        private readonly InvoiceChargeNoticeService $chargeNotices,
        private readonly GatewayRecurrenceLossService $recurrenceLoss,
        private readonly RefundService $refunds,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $sortBy  = $request->string('sort', 'created_at')->value();
        $sortDir = $request->string('direction', 'desc')->value();

        $allowedSorts = ['entity_name', 'plan_name', 'starts_at', 'ends_at', 'created_at'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $sortColumn = match ($sortBy) {
            'entity_name' => 'entities.name',
            'plan_name'   => 'plans.name',
            default       => "subscriptions.{$sortBy}",
        };

        $subscriptions = $this->listQuery($filters)
            ->orderBy($sortColumn, $sortDir)
            ->orderByDesc('subscriptions.id')
            ->paginate(15)
            ->withQueryString();

        $latestIds = $this->latestIds($subscriptions->getCollection());

        return Inertia::render('Panel/Manager/Subscriptions/Index', [
            'subscriptions' => $subscriptions->through(fn (Subscription $s) => $this->toRow($s, $latestIds)),
            'total'         => $subscriptions->total(),
            'summary'       => fn () => $this->summary(),
            'filters'       => [
                ...$filters,
                'sort'      => $sortBy,
                'direction' => $sortDir,
            ],
            'plans'         => fn () => $this->planOptions(),
            'billingCycles' => collect(PlanPrice::SELLABLE_CYCLES)->map(fn (BillingCycle $c) => [
                'value'        => $c->value,
                'label'        => $c->label(),
                'period_label' => Plan::periodLabel($c),
                'months'       => $c->months(),
            ])->values()->toArray(),
            'statuses' => collect(SubscriptionStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ])->values()->toArray(),
            'gateways' => collect(GatewayCode::cases())->map(fn ($g) => [
                'value' => $g->value,
                'label' => strtoupper($g->value),
            ])->values()->toArray(),
            'trialDays'      => SubscriptionSetting::trialDays(),
            'canManagePlans' => $this->canManagePlans($request),
            't'              => trans('manager_subscriptions'),
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $records = $this->listQuery($this->filters($request))
            ->latest('subscriptions.created_at')
            ->orderByDesc('subscriptions.id')
            ->paginate(12);

        $latestIds = $this->latestIds($records->getCollection());

        return response()->json([
            'data' => $records->getCollection()->map(fn (Subscription $r) => $this->toRow($r, $latestIds))->values(),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load('entity', 'plan.prices');

        $latestIds = $this->latestIds(collect([$subscription]));

        return response()->json(['data' => [
            ...$this->toRow($subscription, $latestIds),
            'entity_name'             => $subscription->entity?->name ?? '-',
            'entity_active'           => (bool) ($subscription->entity?->active ?? false),
            'entity_document'         => $subscription->entity?->national_registration,
            'plan_name'               => $subscription->plan?->name ?? '-',
            'plan_prices'             => $subscription->plan ? PlanPricing::cycles($subscription->plan) : [],
            'gateway_customer_id'     => $subscription->gateway_customer_id,
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
            'last_payment_at'         => $subscription->last_payment_at?->toIso8601String(),
            // Mudança de plano agendada (downgrade) — o manager pode desfazer antes da data.
            'scheduled_change' => $this->scheduledChangeRow($subscription),
            // O gateway desativou a recorrência (renovação pelo sistema): aviso até marcar como visto.
            'recurrence_lost' => $this->recurrenceLostRow($subscription),
            // Fatura em aberto que a clínica paga em Minha assinatura ("Enviar cobrança à clínica").
            'open_invoice' => $this->openInvoiceRow($subscription),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            // Avisos da régua de cobrança já enviados (mais recentes primeiro).
            'dunning_steps' => $subscription->dunningSteps()
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($step) => [
                    'step'             => $step->step->value,
                    'label'            => $step->step->label(),
                    'due_on'           => $step->due_on?->toDateString(),
                    'at'               => $step->created_at?->toIso8601String(),
                    'recipients_count' => $step->recipients_count,
                ])->values(),
        ]]);
    }

    /**
     * Linha do tempo da empresa (estilo "licenças"): cada assinatura que ela
     * teve e cada mudança de período/modalidade — quem fez, quando e por quê.
     */
    public function history(Subscription $subscription): JsonResponse
    {
        $entityId = (string) $subscription->entity_id;

        $subscriptions = Subscription::query()
            ->forEntity($entityId)
            ->with('plan')
            ->currentFirst()
            ->get();

        $changes = SubscriptionChange::query()
            ->where('subscription_changes.entity_id', $entityId)
            ->with(['newPlan' => fn ($q) => $q->withTrashed(), 'previousPlan' => fn ($q) => $q->withTrashed()])
            ->leftJoin('users', 'users.id', '=', 'subscription_changes.changed_by')
            ->select('subscription_changes.*', 'users.name as changed_by_name')
            ->orderByDesc('subscription_changes.effective_at')
            ->orderByDesc('subscription_changes.created_at')
            ->limit(200)
            ->get();

        // Mesma regra de latestPerEntity: tentativa recusada pelo gateway nunca é a vigente.
        $latestId = $subscriptions->first(fn (Subscription $s) => ! $s->isFailedActivation())?->id;

        return response()->json(['data' => [
            'subscriptions' => $subscriptions->map(fn (Subscription $s) => [
                'id'           => $s->id,
                'is_current'   => $s->id === $latestId,
                'plan_name'    => $s->plan?->name ?? '-',
                'modality'     => $this->modality($s),
                'status'       => $s->status->value,
                'status_label' => $this->statusLabel($s),
                // Cortesia não tem ciclo de cobrança (o do plano confundiria).
                'billing_cycle' => $s->isComplimentary() ? null : $s->effectiveCycle()?->value,
                'amount'        => $s->recurringAmount(),
                'gateway'       => $s->gateway,
                'starts_at'     => $s->starts_at?->toIso8601String(),
                'ends_at'       => $this->accessEndsAt($s)?->toIso8601String(),
                'open_ended'    => $s->isOpenEnded(),
                'cancelled_at'  => $s->cancelled_at?->toIso8601String(),
                'created_at'    => $s->created_at?->toIso8601String(),
            ])->values(),
            'events' => $changes->map(fn (SubscriptionChange $c) => [
                'id'                => $c->id,
                'subscription_id'   => $c->subscription_id,
                'type'              => $c->change_type,
                'at'                => ($c->effective_at ?? $c->created_at)?->toIso8601String(),
                'actor'             => $c->changed_by_name,
                'source'            => $c->metadata['source'] ?? null,
                'reason'            => $this->historyReason($c),
                'previous'          => $c->metadata['previous'] ?? null,
                'new'               => $c->metadata['new'] ?? null,
                'extension'         => $c->metadata['extension'] ?? null,
                'previous_plan'     => $c->previousPlan?->name,
                'new_plan'          => $c->newPlan?->name,
                'gateway'           => $c->new_gateway_code,
                'gateway_cancelled' => (bool) ($c->metadata['gateway_recurrence_cancelled'] ?? false),
            ])->values(),
        ]]);
    }

    /**
     * Busca de empresas clientes para "Nova assinatura", com a situação atual
     * de cada uma (para avisar que a vigente será substituída).
     */
    public function entities(Request $request): JsonResponse
    {
        $search              = $request->string('search')->trim()->value();
        $withoutSubscription = $request->boolean('without_subscription');
        // CNPJ pode ser alfanumérico (IN RFB 2.229/2024): compara sem pontuação.
        $document = preg_match('/\d/', $search) ? (string) BrazilianFormat::documentChars($search) : '';

        $entities = Entity::query()
            ->where('is_client', true)
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search, $document) {
                $inner->whereLikeUnaccent('name', $search);

                // CNPJ/CPF só quando a busca tem números (senão '%%' casaria tudo).
                if (strlen($document) >= 3) {
                    $inner->orWhere('national_registration', 'like', "%{$document}%");
                }
            }))
            ->when($request->filled('id'), fn ($q) => $q->whereKey($request->string('id')->value()))
            // Card "Sem assinatura": mesmo critério do contador do resumo.
            ->when($withoutSubscription, fn ($q) => $q->whereDoesntHave('subscriptions', fn ($s) => $s->withoutFailedActivations()))
            ->orderBy('name')
            ->limit($withoutSubscription ? 50 : 20)
            ->get(['id', 'name', 'national_registration', 'active', 'created_at']);

        $current = Subscription::query()
            ->whereIn('entity_id', $entities->pluck('id'))
            ->latestPerEntity()
            ->with('plan')
            ->get()
            ->keyBy('entity_id');

        return response()->json(['data' => $entities->map(function (Entity $e) use ($current) {
            $s = $current->get($e->id);

            return [
                'id'         => $e->id,
                'name'       => $e->name,
                'sub_label'  => BrazilianFormat::cpfCnpj($e->national_registration) ?? '',
                'document'   => $e->national_registration,
                'active'     => (bool) $e->active,
                'created_at' => $e->created_at?->toDateString(),
                'current'    => $s ? [
                    'id'             => $s->id,
                    'plan_id'        => $s->plan_id,
                    'plan_name'      => $s->plan?->name,
                    'modality'       => $this->modality($s),
                    'status'         => $s->status->value,
                    'status_label'   => $this->statusLabel($s),
                    'has_access'     => $s->hasAccess(),
                    'open_ended'     => $s->isOpenEnded(),
                    'access_ends_at' => $this->accessEndsAt($s)?->toIso8601String(),
                    'billing_cycle'  => $s->effectiveCycle()?->value,
                ] : null,
            ];
        })->values()]);
    }

    /**
     * Nova assinatura para a empresa — substitui a vigente. Cobrança
     * automática passa pelo gateway; as demais modalidades são do manager.
     */
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $entity = Entity::findOrFail($request->entity_id);
        $plan   = Plan::with('prices')->findOrFail($request->plan_id);
        $mode   = (string) $request->input('mode');
        $reason = $request->optionalReason();

        $previous = Subscription::query()->forEntity((string) $entity->id)->withoutFailedActivations()->currentFirst()->first();

        // Plano pago vigente na cobrança automática: troca de plano/ciclo
        // (upgrade com a diferença proporcional, downgrade agendado) — nunca
        // substitui a assinatura paga.
        if ($mode === 'gateway' && PlanChangeService::isPaidInForce($previous)) {
            return $this->changePlan($request, $entity, $previous, $plan, BillingCycle::from((string) $request->billing_cycle), $reason);
        }

        if ($mode === 'gateway') {
            try {
                $subscription = $this->billingSubscriptionOrchestrator->activateWithGateway(
                    entity: $entity,
                    plan: $plan,
                    cycle: BillingCycle::from((string) $request->billing_cycle),
                    requestedGateway: $request->input('gateway'),
                );
            } catch (SubscriptionSupersededException $e) {
                // Outra alteração da empresa foi feita ao mesmo tempo: ela vale
                // e a cobrança desta foi desfeita.
                return response()->json(['message' => $e->getMessage()], 409);
            } catch (BillingException $e) {
                // Falha do gateway (e do fallback): a mensagem vem do gateway e
                // ajuda o time SaaS a corrigir o cadastro do cliente.
                report($e);

                return response()->json([
                    'message' => __('manager_subscriptions.errors.gateway_failed', ['error' => $e->getMessage()]),
                ], 502);
            }
        } elseif ($mode === 'trial') {
            $subscription = $this->management->startTrial(
                entity: $entity,
                plan: $plan,
                days: (int) $request->trial_days,
                cycle: BillingCycle::tryFrom((string) $request->billing_cycle),
                reason: $reason,
            )->subscription;
        } else {
            $subscription = $this->management->create(
                entity: $entity,
                plan: $plan,
                terms: $this->terms($mode, $request),
                reason: (string) $reason,
            )->subscription;
        }

        $this->audit->recordAdminAction(
            event: "manager.subscription.create.{$mode}",
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'subscription',
            auditableId: (string) $subscription->id,
            reason: $reason ?? __('manager_subscriptions.audit_reason_default', ['mode' => __("manager_subscriptions.modality.{$mode}")]),
            newValues: $this->auditValues($subscription),
            request: $request,
            oldValues: $previous ? $this->auditValues($previous) : null,
        );

        return response()->json([
            'message' => __("manager_subscriptions.flash.created_{$mode}"),
            'data'    => ['id' => $subscription->id],
        ], 201);
    }

    /** Adicionar período (+N dias/meses/anos), estilo "renovar". */
    public function extend(ExtendSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $change = $this->management->extend(
            subscription: $subscription,
            unit: (string) $request->unit,
            quantity: (int) $request->quantity,
            reason: $request->reason(),
        );

        $this->audit->recordAdminAction(
            event: 'manager.subscription.extend',
            targetEntityId: (string) $change->entity_id,
            targetUserId: null,
            auditableType: 'subscription',
            auditableId: (string) $change->subscription_id,
            reason: $request->reason(),
            newValues: [...($change->metadata['new'] ?? []), 'extension' => $change->metadata['extension'] ?? null],
            request: $request,
            oldValues: $change->metadata['previous'] ?? null,
        );

        return response()->json(['message' => __('manager_subscriptions.flash.extended')]);
    }

    /** Alterar plano e período (a assinatura passa a ser cortesia). */
    public function update(UpdateSubscriptionTermsRequest $request, Subscription $subscription): JsonResponse
    {
        $plan = Plan::with('prices')->findOrFail($request->plan_id);

        $change = $this->management->updateTerms(
            subscription: $subscription,
            plan: $plan,
            terms: $this->terms((string) $request->mode, $request),
            reason: $request->reason(),
        );

        $this->audit->recordAdminAction(
            event: 'manager.subscription.terms',
            targetEntityId: (string) $change->entity_id,
            targetUserId: null,
            auditableType: 'subscription',
            auditableId: (string) $change->subscription_id,
            reason: $request->reason(),
            newValues: [...($change->metadata['new'] ?? []), 'gateway_recurrence_cancelled' => $change->metadata['gateway_recurrence_cancelled'] ?? false],
            request: $request,
            oldValues: $change->metadata['previous'] ?? null,
        );

        return response()->json(['message' => __('manager_subscriptions.flash.terms_updated')]);
    }

    /**
     * Prévia do que a cobrança automática faria na empresa: com plano pago
     * vigente, a troca (upgrade: valor proporcional cobrado agora; downgrade:
     * data em que vale); sem, uma nova contratação (change null).
     */
    public function changePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_id'     => ['required', 'uuid'],
            'plan_id'       => ['required', 'uuid'],
            'billing_cycle' => ['required', 'string', Rule::in(PlanPrice::sellableCycleValues())],
        ]);

        $entity  = Entity::query()->where('is_client', true)->findOrFail($validated['entity_id']);
        $plan    = Plan::with('prices')->findOrFail($validated['plan_id']);
        $cycle   = BillingCycle::from($validated['billing_cycle']);
        $current = Subscription::query()->forEntity((string) $entity->id)->withoutFailedActivations()->with('plan')->currentFirst()->first();

        if (! PlanChangeService::isPaidInForce($current)) {
            return response()->json(['data' => ['change' => null]]);
        }

        if ($current->plan_id === $plan->id && $current->effectiveCycle() === $cycle) {
            return response()->json(['data' => ['change' => ['type' => 'current'], 'gateway' => $current->gateway]]);
        }

        $amount = $plan->priceFor($cycle);

        if ($amount === null || $amount <= 0) {
            return response()->json(['message' => __('manager_subscriptions.errors.cycle_not_offered', ['cycle' => $cycle->label(), 'plan' => $plan->name])], 422);
        }

        return response()->json(['data' => [
            'change'  => $this->planChanges->quote($current, $plan, $cycle, (float) $amount),
            'gateway' => $current->gateway,
        ]]);
    }

    /** Desfaz a mudança agendada (downgrade) antes da data — com justificativa. */
    public function cancelScheduledChange(DestructiveActionRequest $request, Subscription $subscription): JsonResponse
    {
        $before = $subscription->scheduledChange();

        try {
            $updated = $this->planChanges->cancelScheduled($subscription, (string) Str::uuid(), 'manager', $request->reason());
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $this->audit->recordAdminAction(
            event: 'manager.subscription.plan_change.cancel_scheduled',
            targetEntityId: (string) $updated->entity_id,
            targetUserId: null,
            auditableType: 'subscription',
            auditableId: (string) $updated->id,
            reason: $request->reason(),
            newValues: $this->auditValues($updated),
            request: $request,
            oldValues: ['scheduled_change' => $before],
        );

        return response()->json(['message' => __('manager_subscriptions.flash.scheduled_change_cancelled')]);
    }

    /**
     * Troca de plano/ciclo pedida pelo manager numa assinatura paga e
     * vigente (PlanChangeService — as mesmas regras do checkout da
     * clínica): upgrade gera a fatura da diferença proporcional (a clínica
     * paga em "Minha assinatura"; o plano muda quando ela é paga);
     * downgrade fica agendado para o fim do período pago.
     */
    private function changePlan(StoreSubscriptionRequest $request, Entity $entity, Subscription $current, Plan $plan, BillingCycle $cycle, ?string $reason): JsonResponse
    {
        if ($current->plan_id === $plan->id && $current->effectiveCycle() === $cycle) {
            return response()->json(['message' => __('manager_subscriptions.errors.plan_change_same')], 422);
        }

        // A troca mantém o gateway da assinatura paga (o cliente, o cartão e a
        // cobrança estão lá): pedir outro gateway é recusado, nunca ignorado.
        $requested = (string) $request->input('gateway', '');

        if ($requested !== '' && $requested !== (string) $current->gateway) {
            return response()->json([
                'message' => __('manager_subscriptions.errors.plan_change_gateway', ['gateway' => (string) $current->gateway]),
                'errors'  => ['gateway' => [__('manager_subscriptions.errors.plan_change_gateway', ['gateway' => (string) $current->gateway])]],
            ], 422);
        }

        $amount = (float) $plan->priceFor($cycle);
        $quote  = $this->planChanges->quote($current, $plan, $cycle, $amount);
        $before = $this->auditValues($current);
        $corr   = (string) Str::uuid();

        try {
            if ($quote['type'] === PlanChangeService::TYPE_SCHEDULED) {
                $subscription = $this->planChanges->schedule($current, $plan, $cycle, $quote, $corr, 'manager', $reason);
                $message      = __('manager_subscriptions.flash.plan_change_scheduled', ['date' => CarbonImmutable::parse($quote['effective_at'])->format('d/m/Y')]);
                $invoiceId    = null;
            } else {
                [$invoice, $toCancel] = $this->planChanges->upgradeInvoice($current, $plan, $cycle, $quote, $this->cycles->firstDueDate(), $corr, 'manager', $reason);
                $this->planChanges->cancelCharges($current, $toCancel, $corr);

                $subscription = $current->fresh('plan');
                $message      = __('manager_subscriptions.flash.plan_change_upgrade', ['amount' => Number::currency((float) $quote['amount_now'], in: 'BRL', locale: str_replace('_', '-', app()->getLocale()))]);
                $invoiceId    = (string) $invoice->id;
            }
        } catch (CheckoutException $e) {
            // A assinatura mudou enquanto o pedido era feito (renovou, trocou).
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $this->audit->recordAdminAction(
            event: "manager.subscription.plan_change.{$quote['type']}",
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'subscription',
            auditableId: (string) $subscription->id,
            reason: $reason ?? __('manager_subscriptions.audit_reason_plan_change'),
            newValues: ['plan_id' => (string) $plan->id, 'billing_cycle' => $cycle->value, 'change' => $quote, 'invoice_id' => $invoiceId],
            request: $request,
            oldValues: $before,
        );

        return response()->json([
            'message' => $message,
            'data'    => ['id' => $subscription->id, 'change' => $quote, 'invoice_id' => $invoiceId],
        ], 201);
    }

    /** @return array<string, mixed>|null último envio da cobrança desta fatura à clínica */
    private function chargeNoticeRow(Invoice $invoice): ?array
    {
        $last = $this->chargeNotices->lastNotice($invoice);

        if ($last === null) {
            return null;
        }

        return [
            'at'       => $last->created_at?->toIso8601String(),
            'by'       => $last->changed_by ? User::query()->whereKey($last->changed_by)->value('name') : null,
            'channels' => (array) ($last->metadata['channels'] ?? []),
        ];
    }

    /**
     * Fatura em aberto da assinatura que a clínica paga em Minha assinatura —
     * a diferença do upgrade primeiro. Null sem nenhuma.
     *
     * @return array<string, mixed>|null
     */
    private function openInvoiceRow(Subscription $subscription): ?array
    {
        $invoice = $subscription->invoices()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value, InvoiceStatus::Cancelled->value])
            ->orderByRaw('CASE WHEN billing_reason = ? THEN 0 ELSE 1 END', [Invoice::BILLING_REASON_PLAN_CHANGE])
            ->orderBy('due_at')
            ->get()
            ->first(fn (Invoice $invoice) => $this->chargeNotices->canSend($subscription, $invoice));

        if ($invoice === null) {
            return null;
        }

        return [
            'id'          => (string) $invoice->id,
            'reference'   => $invoice->reference,
            'amount'      => (float) $invoice->amount,
            'currency'    => $invoice->currency,
            'due_at'      => $invoice->due_at?->toIso8601String(),
            'plan_change' => $invoice->isPlanChange(),
            'last_notice' => $this->chargeNoticeRow($invoice),
        ];
    }

    /** @return array<string, mixed>|null aviso de recorrência desativada pelo gateway (não visto) */
    private function recurrenceLostRow(Subscription $subscription): ?array
    {
        if ($subscription->recurrence_alert_at === null) {
            return null;
        }

        $change = SubscriptionChange::query()
            ->where('subscription_id', $subscription->id)
            ->where('change_type', GatewayRecurrenceLossService::CHANGE_TYPE)
            ->latest('created_at')
            ->first();

        return [
            'at'                       => $subscription->recurrence_alert_at->toIso8601String(),
            'gateway'                  => $change?->metadata['gateway'] ?? $subscription->gateway,
            'gateway_event'            => $change?->metadata['gateway_event'] ?? null,
            'external_subscription_id' => $change?->metadata['external_subscription_id'] ?? null,
            'next_billing_at'          => $subscription->nextBillingDate()?->toIso8601String(),
            'access_until'             => $subscription->ends_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function scheduledChangeRow(Subscription $subscription): ?array
    {
        $change = $subscription->scheduledChange();

        if ($change === null) {
            return null;
        }

        $cycle = BillingCycle::tryFrom((string) ($change['billing_cycle'] ?? ''));

        return [
            'plan_name'     => $change['plan_name'] ?? Plan::withTrashed()->find($change['plan_id'])?->name,
            'cycle'         => $cycle?->value,
            'cycle_label'   => $cycle?->label(),
            'amount'        => isset($change['amount']) ? (float) $change['amount'] : null,
            'effective_at'  => $change['effective_at'],
            'source'        => $change['source'] ?? 'checkout',
            'justification' => $change['justification'] ?? null,
            'can_cancel'    => ! ($change['terms_applied'] ?? false) && CarbonImmutable::parse($change['effective_at'])->isFuture(),
        ];
    }

    /**
     * "Enviar cobrança à clínica": e-mail + WhatsApp aos contatos de cobrança
     * com o link para pagar a fatura em Minha assinatura (dentro do sistema).
     * Fatura paga/cancelada: 409; um envio por fatura a cada 10 min: 429.
     */
    public function sendCharge(Request $request, Subscription $subscription, Invoice $invoice): JsonResponse
    {
        abort_unless($invoice->subscription_id === $subscription->id, 404);

        $result = $this->chargeNotices->send($subscription, $invoice, $request->user());

        $this->audit->recordAdminAction(
            event: 'manager.subscription.charge_notice',
            targetEntityId: (string) $subscription->entity_id,
            targetUserId: null,
            auditableType: 'invoice',
            auditableId: (string) $invoice->id,
            reason: __('manager_subscriptions.charge_notice.audit_reason'),
            newValues: [...$result, 'invoice_id' => (string) $invoice->id, 'reference' => $invoice->reference],
            request: $request,
        );

        return response()->json([
            'message' => trans_choice(
                $result['whatsapp'] > 0 ? 'manager_subscriptions.charge_notice.sent_with_whatsapp' : 'manager_subscriptions.charge_notice.sent',
                $result['recipients'],
                ['count' => $result['recipients'], 'whatsapp' => $result['whatsapp']],
            ),
            'data' => [...$result, 'invoice_id' => (string) $invoice->id],
        ]);
    }

    /** O time viu o aviso de recorrência desativada pelo gateway: sai da lista de alertas. */
    public function acknowledgeRecurrenceAlert(Request $request, Subscription $subscription): JsonResponse
    {
        if ($subscription->recurrence_alert_at !== null) {
            $alertAt = $subscription->recurrence_alert_at->toIso8601String();

            $this->recurrenceLoss->acknowledge($subscription);

            $this->audit->recordAdminAction(
                event: 'manager.subscription.recurrence_alert.acknowledge',
                targetEntityId: (string) $subscription->entity_id,
                targetUserId: null,
                auditableType: 'subscription',
                auditableId: (string) $subscription->id,
                reason: __('manager_subscriptions.recurrence_lost.audit_reason'),
                newValues: ['recurrence_alert_at' => null],
                request: $request,
                oldValues: ['recurrence_alert_at' => $alertAt],
            );
        }

        return response()->json(['message' => __('manager_subscriptions.recurrence_lost.acknowledged')]);
    }

    public function invoices(Subscription $subscription): JsonResponse
    {
        $invoices = Invoice::query()
            ->with(['payments.refunds.requester'])
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id'        => $inv->id,
                'reference' => $inv->reference,
                // "Enviar cobrança à clínica": só a que ela paga agora em Minha assinatura.
                'can_send_charge'    => $this->chargeNotices->canSend($subscription, $inv),
                'last_charge_notice' => $this->chargeNoticeRow($inv),
                'status'             => $inv->status->value,
                'status_label'       => $this->invoiceStatusLabel($inv->status->value),
                'status_badge'       => $this->invoiceStatusBadge($inv->status->value),
                'amount'             => (float) $inv->amount,
                'currency'           => $inv->currency,
                'billing_reason'     => $inv->billing_reason,
                'gateway_code'       => $inv->gateway_code,
                'period_start'       => $inv->period_start?->toDateString(),
                'period_end'         => $inv->period_end?->toDateString(),
                'due_at'             => $inv->due_at?->toIso8601String(),
                'paid_at'            => $inv->paid_at?->toIso8601String(),
                'created_at'         => $inv->created_at->toIso8601String(),
                'refunded_amount'    => (float) $inv->refunded_amount,
                'payments'           => $inv->payments->map(fn ($p) => [
                    'id'              => $p->id,
                    'status'          => $p->status,
                    'status_label'    => __('manager_subscriptions.payment_status.' . ($p->status?->value ?? (string) $p->status)),
                    'status_badge'    => $this->paymentStatusBadge((string) ($p->status?->value ?? $p->status)),
                    'amount'          => (float) $p->amount,
                    'refunded_amount' => (float) $p->refunded_amount,
                    // Estorno pelo manager: só no gateway com estorno pela API e pagamento pago.
                    'refund'  => $this->refunds->options($p),
                    'refunds' => $p->refunds->sortByDesc('created_at')->values()->map(fn ($r) => [
                        ...$this->refunds->row($r),
                        'check_url' => route('manager.subscriptions.payments.refunds.check', ['subscription' => $subscription->id, 'invoice' => $inv->id, 'payment' => $p->id, 'refund' => $r->id]),
                    ])->all(),
                    'refund_url'          => route('manager.subscriptions.payments.refund', ['subscription' => $subscription->id, 'invoice' => $inv->id, 'payment' => $p->id]),
                    'currency'            => $p->currency,
                    'gateway_code'        => $p->gateway_code,
                    'external_payment_id' => $p->external_payment_id,
                    'paid_at'             => $p->paid_at?->toIso8601String(),
                    'failed_at'           => $p->failed_at?->toIso8601String(),
                    'created_at'          => $p->created_at->toIso8601String(),
                ]),
            ]);

        return response()->json(['data' => $invoices]);
    }

    /**
     * Estorna (total ou parcial) um pagamento pago da assinatura, com
     * justificativa e auditoria. Fica "estorno solicitado" até o gateway
     * confirmar (webhook ou refunds[] DONE) — RefundService.
     */
    public function refund(RefundPaymentRequest $request, Subscription $subscription, Invoice $invoice, Payment $payment): JsonResponse
    {
        abort_unless($invoice->subscription_id === $subscription->id && $payment->invoice_id === $invoice->id, 404);

        try {
            $refund = $this->refunds->request($payment, $request->amount(), $request->reason(), $request->user());
        } catch (BillingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->recordAdminAction(
            event: 'manager.subscription.payment.refund',
            targetEntityId: (string) $subscription->entity_id,
            targetUserId: null,
            auditableType: 'payment',
            auditableId: (string) $payment->id,
            reason: $request->reason(),
            newValues: [
                'refund_id'           => (string) $refund->id,
                'amount'              => (float) $refund->amount,
                'partial'             => (bool) $refund->partial,
                'status'              => $refund->status,
                'gateway'             => $refund->gateway_code,
                'external_payment_id' => $refund->external_payment_id,
                'invoice_reference'   => $invoice->reference,
            ],
            request: $request,
            oldValues: ['payment_status' => $payment->status?->value, 'refunded_amount' => (float) $payment->refunded_amount],
        );

        return response()->json([
            'message' => __(match (true) {
                $refund->status === BillingRefund::STATUS_DONE               => 'manager_subscriptions.refund.done',
                $refund->gateway_state === BillingRefund::STATE_QUEUED       => 'manager_subscriptions.refund.queued',
                $refund->gateway_state === BillingRefund::STATE_INCONCLUSIVE => 'manager_subscriptions.refund.inconclusive',
                default                                                      => 'manager_subscriptions.refund.requested',
            }),
            'data' => $this->refunds->row($refund),
        ]);
    }

    /**
     * Confere no gateway o pedido de estorno ainda "solicitado" (sem
     * resposta definitiva, aguardando o gateway ou parado): concluído,
     * em andamento ou liberado para um novo pedido — RefundService::check.
     */
    public function checkRefund(Request $request, Subscription $subscription, Invoice $invoice, Payment $payment, BillingRefund $refund): JsonResponse
    {
        abort_unless($invoice->subscription_id === $subscription->id && $payment->invoice_id === $invoice->id && $refund->payment_id === $payment->id, 404);

        $before  = ['status' => $refund->status, 'gateway_state' => $refund->gateway_state];
        $outcome = $this->refunds->check($refund);
        $fresh   = $refund->fresh() ?? $refund;

        $this->audit->recordAdminAction(
            event: 'manager.subscription.payment.refund_check',
            targetEntityId: (string) $subscription->entity_id,
            targetUserId: null,
            auditableType: 'payment',
            auditableId: (string) $payment->id,
            reason: __('manager_subscriptions.refund.check_audit_reason'),
            newValues: ['refund_id' => (string) $refund->id, 'outcome' => $outcome, 'status' => $fresh->status, 'check_note' => $fresh->check_note],
            request: $request,
            oldValues: $before,
        );

        return response()->json([
            'message' => __("manager_subscriptions.refund.check_results.{$outcome}"),
            'outcome' => $outcome,
            'data'    => $this->refunds->row($fresh),
        ]);
    }

    public function retries(Subscription $subscription): JsonResponse
    {
        $retries = BillingRetrySchedule::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BillingRetrySchedule $r) => [
                'id'             => $r->id,
                'attempt_number' => $r->attempt_number,
                'status'         => $r->status,
                'status_badge'   => $this->retryStatusBadge($r->status),
                'gateway_code'   => $r->gateway_code,
                'scheduled_for'  => $r->scheduled_for?->toIso8601String(),
                'executed_at'    => $r->executed_at?->toIso8601String(),
                'result_message' => $r->result_message,
                'created_at'     => $r->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $retries]);
    }

    public function blockAccess(Request $request): JsonResponse
    {
        $request->validate([
            'entity_id' => ['required', 'uuid', 'exists:entities,id'],
            'active'    => ['required', 'boolean'],
            'reason'    => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $entity = Entity::findOrFail($request->entity_id);
        $active = $request->boolean('active');

        $entity->update(['active' => $active]);
        $entity->entityUsers()->update(['active' => $active]);

        // Audit estruturado: trace por que o acesso foi alterado.
        $this->audit->recordAdminAction(
            event: $active ? 'manager.entity.unblock' : 'manager.entity.block',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'entity',
            auditableId: (string) $entity->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['active' => $active, 'cascaded_to_entity_users' => true],
            request: $request,
        );

        return response()->json([
            'message' => $active ? __('manager_subscriptions.flash.unblocked') : __('manager_subscriptions.flash.blocked'),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $request->validate([
            'entity_id' => ['required', 'uuid', 'exists:entities,id'],
            'reason'    => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $entity = Entity::findOrFail($request->entity_id);
        // A vigente mesmo sem acesso no momento (ex.: em atraso) e as linhas
        // do código anterior aguardando conciliação (inclusive a expirada que
        // o gateway segue cobrando): todas param, inclusive no gateway.
        $subscriptions = $this->subscriptionService->cancellableOf($entity);

        if ($subscriptions->isEmpty()) {
            return response()->json(['message' => __('manager_subscriptions.errors.nothing_to_cancel')], 404);
        }

        $oldStatus = $subscriptions->first()->status->value;

        // A justificativa e quem cancelou também vão para o histórico da empresa.
        $cancelled = $subscriptions->map(fn (Subscription $subscription) => $this->billingCancellationService->cancel(
            subscription: $subscription,
            entity: $entity,
            reason: CancellationReason::AdminAction,
            source: 'manager',
            notes: trim((string) $request->input('reason')),
            cancelAtGateway: true,
            changedBy: (string) $request->user()->id,
        ));
        $subscription = $cancelled->first();

        // Audit estruturado da ação destrutiva, com a justificativa do admin.
        $this->audit->recordAdminAction(
            event: 'manager.subscription.cancel',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'subscription',
            auditableId: (string) $subscription->id,
            reason: trim((string) $request->input('reason')),
            newValues: [
                'cancellation_reason'        => CancellationReason::AdminAction->value,
                'cancel_at_gateway'          => true,
                'new_status'                 => $subscription->status->value,
                'cancelled_subscription_ids' => $cancelled->pluck('id')->all(),
            ],
            request: $request,
            oldValues: ['status' => $oldStatus],
        );

        return response()->json([
            'message' => __('manager_subscriptions.flash.cancelled'),
            'data'    => ['id' => $subscription->id],
        ]);
    }

    // ── Consulta da listagem ─────────────────────────────────────────────────

    /** @return array{search: string, status: string, plan: string, mode: string, scope: string} */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->value();
        $mode   = $request->string('mode')->value();
        $plan   = $request->string('plan')->value();

        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($status, self::STATUS_FILTERS, true) ? $status : '',
            'plan'   => preg_match('/^[0-9a-f-]{36}$/i', $plan) ? $plan : '',
            'mode'   => in_array($mode, self::MODALITIES, true) ? $mode : '',
            // Padrão: uma linha por empresa (a assinatura vigente); "all" mostra o histórico.
            'scope' => $request->string('scope')->value() === 'all' ? 'all' : 'current',
        ];
    }

    /** @param array{search: string, status: string, plan: string, mode: string, scope: string} $filters */
    private function listQuery(array $filters): Builder
    {
        $query = Subscription::query()
            ->select('subscriptions.*', 'entities.name as entity_name', 'entities.active as entity_active', 'plans.name as plan_name')
            ->join('entities', 'subscriptions.entity_id', '=', 'entities.id')
            ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->with('plan.prices');

        if ($filters['scope'] === 'current') {
            $query->latestPerEntity();
        }

        if ($filters['search'] !== '') {
            $query->where(function ($q) use ($filters) {
                $q->whereLikeUnaccent('entities.name', $filters['search'])
                    ->orWhereLikeUnaccent('plans.name', $filters['search']);
            });
        }

        if ($filters['plan'] !== '') {
            $query->where('subscriptions.plan_id', $filters['plan']);
        }

        match ($filters['status']) {
            ''           => null,
            'accessible' => $query->accessible(),
            // Sem acesso: venceu/cancelou e já passou o período de graça.
            'no_access' => $query->whereNot(fn ($q) => $q->accessible()),
            // Contratação aguardando o 1º pagamento não está "em atraso"; a
            // linha antiga aguardando conciliação fica só em "Revisar cobrança".
            'awaiting_payment' => $query->awaitingFirstPayment()
                ->whereNot(fn ($q) => $q->needingBillingReconciliation()),
            'past_due' => $query->where('subscriptions.status', $filters['status'])
                ->whereNot(fn ($q) => $q->awaitingFirstPayment())
                ->whereNot(fn ($q) => $q->needingBillingReconciliation()),
            'needs_review'     => $query->needingBillingReconciliation(),
            'recurrence_alert' => $query->whereNotNull('subscriptions.recurrence_alert_at'),
            default            => $query->where('subscriptions.status', $filters['status']),
        };

        match ($filters['mode']) {
            ''      => null,
            'trial' => $query->whereNull('subscriptions.billing_mode'),
            default => $query->where('subscriptions.billing_mode', $filters['mode']),
        };

        return $query;
    }

    /**
     * Ids das assinaturas que são a mais recente da sua empresa, entre as
     * linhas exibidas (no modo histórico só a vigente pode ser alterada).
     *
     * @param Collection<int, Subscription> $rows
     *
     * @return array<string, true>
     */
    private function latestIds(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        return Subscription::query()
            ->whereIn('entity_id', $rows->pluck('entity_id')->unique()->values())
            ->latestPerEntity()
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    /**
     * Situação das empresas pela assinatura vigente de cada uma — atalhos de
     * filtro no topo da tela.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $current = Subscription::query()->latestPerEntity();

        $accessible = (clone $current)->accessible();
        $companies  = (clone $current)->count();

        return [
            'companies'     => $companies,
            'trial'         => (clone $accessible)->where('status', SubscriptionStatus::Trial->value)->count(),
            'gateway'       => (clone $accessible)->where('billing_mode', SubscriptionBillingMode::Gateway->value)->count(),
            'complimentary' => (clone $accessible)->where('billing_mode', SubscriptionBillingMode::Complimentary->value)->count(),
            'past_due'      => (clone $current)->where('status', SubscriptionStatus::PastDue->value)
                ->whereNot(fn ($q) => $q->awaitingFirstPayment())
                ->whereNot(fn ($q) => $q->needingBillingReconciliation())
                ->count(),
            'awaiting_payment' => (clone $current)->awaitingFirstPayment()->whereNot(fn ($q) => $q->needingBillingReconciliation())->count(),
            // Cobrança automática do código anterior aguardando conciliação.
            'needs_review' => (clone $current)->needingBillingReconciliation()->count(),
            // O gateway desativou a recorrência (aviso ainda não visto pelo time).
            'recurrence_alert' => (clone $current)->whereNotNull('subscriptions.recurrence_alert_at')->count(),
            'without_access'   => $companies - (clone $accessible)->count(),
            // Só tentativas recusadas pelo gateway: nenhuma assinatura de fato.
            'no_subscription' => Entity::query()->where('is_client', true)
                ->whereDoesntHave('subscriptions', fn ($q) => $q->withoutFailedActivations())
                ->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function planOptions(): array
    {
        return Plan::query()
            ->with('prices')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Plan $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'active'        => (bool) $p->active,
                'default_cycle' => $p->defaultCycle()?->value,
                'prices'        => PlanPricing::cycles($p),
            ])
            ->values()
            ->toArray();
    }

    private function canManagePlans(Request $request): bool
    {
        $user   = $request->user();
        $entity = Entity::find(session('selected_entity_id'));

        return $user && $entity
            && ($user->isOwnerOfEntity($entity) || $user->hasAnyRoleInEntity($entity, [SaasRule::Admin->value]));
    }

    // ── Apresentação ────────────────────────────────────────────────────────

    /**
     * @param array<string, true> $latestIds
     *
     * @return array<string, mixed>
     */
    private function toRow(Subscription $s, array $latestIds): array
    {
        $accessEndsAt = $this->accessEndsAt($s);
        $isCurrent    = isset($latestIds[(string) $s->id]);
        $modality     = $this->modality($s);
        $dunning      = $s->dunningStage();
        // Cortesia não tem ciclo de cobrança (o do plano confundiria).
        $cycle = $s->isComplimentary() ? null : $s->effectiveCycle();

        return [
            'id'                     => $s->id,
            'entity_id'              => $s->entity_id,
            'entity_name'            => $s->entity_name ?? $s->entity?->name,
            'entity_active'          => (bool) ($s->entity_active ?? $s->entity?->active),
            'plan_id'                => $s->plan_id,
            'plan_name'              => $s->plan_name ?? $s->plan?->name ?? '-',
            'status'                 => $s->status->value,
            'status_label'           => $this->statusLabel($s),
            'status_badge'           => $s->isAwaitingFirstPayment() ? 'badge-soft-info' : $s->status->badgeClass(),
            'modality'               => $modality,
            'billing_mode'           => $s->billing_mode?->value,
            'billing_cycle'          => $cycle?->value,
            'billing_cycle_label'    => $cycle?->label(),
            'amount'                 => $s->recurringAmount(),
            'billing_state'          => $s->billing_state,
            'billing_state_badge'    => $this->billingStateBadge($s->billing_state),
            'billing_state_label'    => $this->billingStateLabel($s->billing_state),
            'last_billing_error'     => $s->last_billing_error,
            'gateway'                => $s->gateway,
            'has_gateway_recurrence' => $s->hasGatewayRecurrence(),
            'starts_at'              => $s->starts_at?->toIso8601String(),
            'ends_at'                => $s->ends_at?->toIso8601String(),
            'trial_ends_at'          => $s->trial_ends_at?->toIso8601String(),
            'access_ends_at'         => $accessEndsAt?->toIso8601String(),
            'days_left'              => $accessEndsAt ? (int) floor(now()->diffInDays($accessEndsAt, false)) : null,
            'open_ended'             => $s->isOpenEnded(),
            'is_accessible'          => $s->status->isAccessible(),
            'has_access'             => $s->hasAccess(),
            'is_current'             => $isCurrent,
            'can_extend'             => $isCurrent && $this->canExtend($s),
            'can_change_terms'       => $isCurrent && $s->status !== SubscriptionStatus::Cancelled,
            'needs_attention'        => in_array($s->billing_state, ['past_due', 'chargeback', 'error', 'payment_failed'], true),
            // Cobrança automática do código anterior aguardando conciliação
            // (billing:reconcile-legacy): "Revisar cobrança".
            'needs_reconciliation' => $s->needsBillingReconciliation(),
            // O gateway desativou a recorrência e a cobrança passou para o sistema (aviso não visto).
            'recurrence_alert' => $s->recurrence_alert_at?->toIso8601String(),
            // Régua de cobrança: nível de acesso, dias de atraso e etapa atual.
            'access_level'        => $s->accessLevel()->value,
            'days_overdue'        => $this->daysOverdue($s, $dunning),
            'dunning_stage'       => $dunning?->value,
            'dunning_stage_label' => $dunning?->label(),
            'created_at'          => $s->created_at?->toIso8601String(),
        ];
    }

    /**
     * Dias desde o vencimento não pago: atraso de quem já pagou (desde o
     * vencimento real — na linha conciliada do código anterior a régua conta
     * da conciliação) ou 1ª cobrança vencida.
     */
    private function daysOverdue(Subscription $s, ?DunningStep $stage): ?int
    {
        if (in_array($stage, [DunningStep::FirstChargeOverdue, DunningStep::FirstChargeTerminated], true)) {
            return DunningSchedule::daysSince($s->next_billing_at);
        }

        $due = $s->unpaidDueDate();

        return $due ? DunningSchedule::daysSince($due) : null;
    }

    /**
     * Mesmas regras do SubscriptionManagementService::extend (para desabilitar
     * o botão com o motivo). Cobrança automática não recebe período: ele
     * acompanha os pagamentos.
     */
    private function canExtend(Subscription $s): bool
    {
        return match ($s->status) {
            SubscriptionStatus::Trial   => true,
            SubscriptionStatus::Active  => $s->ends_at !== null && $s->billing_mode !== SubscriptionBillingMode::Gateway,
            SubscriptionStatus::Expired => $s->billing_mode === null
                ? $s->trial_ends_at !== null && $s->ends_at === null
                : $s->billing_mode === SubscriptionBillingMode::Complimentary,
            default => false,
        };
    }

    /** Situação para o manager: contratação aguardando o 1º pagamento não é "em atraso". */
    private function statusLabel(Subscription $s): string
    {
        return $s->isAwaitingFirstPayment()
            ? __('manager_subscriptions.status_awaiting_first_payment')
            : $s->status->label();
    }

    /**
     * Motivo de uma mudança no histórico: a justificativa escrita (manager)
     * ou, nas mudanças do sistema (régua, cancelamentos automáticos,
     * conciliação do código anterior), o motivo traduzido — nunca o código
     * cru ('non_payment', 'admin_action', 'billing:reconcile-legacy').
     */
    private function historyReason(SubscriptionChange $change): ?string
    {
        $text = $change->metadata['reason_text'] ?? $change->metadata['notes'] ?? null;

        if (filled($text)) {
            return (string) $text;
        }

        $code = CancellationReason::tryFrom((string) $change->reason);

        if ($code) {
            return __("manager_subscriptions.history_reason_code.{$code->value}");
        }

        // Motivos do sistema com rótulo próprio (recorrência desativada, cobrança enviada).
        if (filled($change->reason) && Lang::has("manager_subscriptions.history_reason_code.{$change->reason}")) {
            return __("manager_subscriptions.history_reason_code.{$change->reason}");
        }

        // Conciliação do código anterior: o resultado dela (billing_console).
        $reconcileKey = 'billing_console.reconcile.reason.' . ($change->metadata['reason_key'] ?? '');

        if (filled($change->metadata['reason_key'] ?? null) && Lang::has($reconcileKey)) {
            return __($reconcileKey);
        }

        return ($change->metadata['source'] ?? null) === 'billing:reconcile-legacy' ? null : $change->reason;
    }

    private function modality(Subscription $s): string
    {
        return $s->billing_mode === null ? 'trial' : $s->billing_mode->value;
    }

    /** Até quando a empresa tem acesso: fim do trial ou do período; null = sem término (registro antigo). */
    private function accessEndsAt(Subscription $s): ?CarbonImmutable
    {
        $end = $s->billing_mode === null && $s->trial_ends_at ? $s->trial_ends_at : $s->ends_at;

        return $end ? CarbonImmutable::instance($end) : null;
    }

    /** Condições da modalidade sem gateway a partir do formulário. */
    private function terms(string $mode, Request $request): SubscriptionTerms
    {
        $startsAt = CarbonImmutable::parse($request->input('starts_at') ?: now()->toDateString())->startOfDay();
        $endsAt   = $request->filled('ends_at') ? CarbonImmutable::parse((string) $request->input('ends_at'))->endOfDay() : null;

        return SubscriptionTerms::complimentary($startsAt, $endsAt);
    }

    /** @return array<string, mixed> */
    private function auditValues(Subscription $s): array
    {
        return [
            'plan_id'       => $s->plan_id,
            'status'        => $s->status?->value,
            'billing_mode'  => $s->billing_mode?->value,
            'billing_cycle' => $s->billing_cycle?->value,
            'amount'        => $s->amount !== null ? (float) $s->amount : null,
            'starts_at'     => $s->starts_at?->toIso8601String(),
            'ends_at'       => $s->ends_at?->toIso8601String(),
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'gateway'       => $s->gateway,
        ];
    }

    private function billingStateBadge(?string $state): string
    {
        return match ($state) {
            'paid'               => 'badge-soft-success',
            'pending'            => 'badge-soft-warning',
            'past_due'           => 'badge-soft-orange',
            'chargeback'         => 'badge-soft-danger',
            'error'              => 'badge-soft-danger',
            'cancelled'          => 'badge-soft-secondary',
            'pending_activation' => 'badge-soft-info',
            'payment_failed'     => 'badge-soft-orange',
            default              => 'badge-soft-secondary',
        };
    }

    private function billingStateLabel(?string $state): ?string
    {
        return $state === null ? null : __("manager_subscriptions.billing_state.{$state}");
    }

    private function invoiceStatusLabel(string $status): string
    {
        return __("manager_subscriptions.invoice_status.{$status}");
    }

    private function invoiceStatusBadge(string $status): string
    {
        return match ($status) {
            'paid'      => 'badge-soft-success',
            'pending'   => 'badge-soft-warning',
            'overdue'   => 'badge-soft-orange',
            'failed'    => 'badge-soft-danger',
            'refunded'  => 'badge-soft-info',
            'cancelled' => 'badge-soft-secondary',
            default     => 'badge-soft-secondary',
        };
    }

    private function paymentStatusBadge(string $status): string
    {
        return match ($status) {
            'paid'       => 'badge-soft-success',
            'pending'    => 'badge-soft-warning',
            'failed'     => 'badge-soft-danger',
            'refunded'   => 'badge-soft-info',
            'chargeback' => 'badge-soft-danger',
            'cancelled'  => 'badge-soft-secondary',
            'duplicate'  => 'badge-soft-orange',
            default      => 'badge-soft-secondary',
        };
    }

    private function retryStatusBadge(string $status): string
    {
        return match ($status) {
            'pending'   => 'badge-soft-warning',
            'executed'  => 'badge-soft-success',
            'skipped'   => 'badge-soft-secondary',
            'cancelled' => 'badge-soft-secondary',
            default     => 'badge-soft-secondary',
        };
    }
}
