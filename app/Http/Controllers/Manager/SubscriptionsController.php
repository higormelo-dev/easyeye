<?php

namespace App\Http\Controllers\Manager;

use App\Enums\Billing\{CancellationReason, GatewayCode};
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\SubscriptionRequest;
use App\Models\Billing\{BillingRetrySchedule, Invoice};
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting};
use App\Services\Audit\AuditLogger;
use App\Services\Billing\{BillingCancellationService, BillingSubscriptionOrchestrator};
use App\Services\{SubscriptionService, TrialService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

class SubscriptionsController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly TrialService $trialService,
        private readonly BillingSubscriptionOrchestrator $billingSubscriptionOrchestrator,
        private readonly BillingCancellationService $billingCancellationService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $search  = $request->string('search')->trim()->value();
        $sortBy  = $request->string('sort', 'created_at')->value();
        $sortDir = $request->string('direction', 'desc')->value();

        $allowedSorts = ['entity_name', 'plan_name', 'next_billing_at', 'starts_at', 'ends_at', 'created_at'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = Subscription::query()
            ->select('subscriptions.*', 'entities.name as entity_name', 'entities.active as entity_active', 'plans.name as plan_name')
            ->join('entities', 'subscriptions.entity_id', '=', 'entities.id')
            ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id');

        if ($search !== '') {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(entities.name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(plans.name) LIKE ?', ["%{$lower}%"]);
            });
        }

        $sortColumn = match ($sortBy) {
            'entity_name' => 'entities.name',
            'plan_name'   => 'plans.name',
            default       => "subscriptions.{$sortBy}",
        };

        $subscriptions = $query->orderBy($sortColumn, $sortDir)->paginate(15)->withQueryString();

        return Inertia::render('Panel/Manager/Subscriptions/Index', [
            'subscriptions' => $subscriptions->through(fn ($s) => $this->toTableRow($s)),
            'total'         => fn () => Subscription::count(),
            'filters'       => $request->only(['search', 'sort', 'direction']),
            'plans'         => Plan::active()->orderBy('sort_order')->get()->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'price' => $p->price ? 'R$ ' . number_format((float) $p->price, 2, ',', '.') : null,
            ])->values()->toArray(),
            'billingCycles' => collect(BillingCycle::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ])->values()->toArray(),
            'statuses' => collect(SubscriptionStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ])->values()->toArray(),
            'gateways' => collect(GatewayCode::cases())->map(fn ($g) => [
                'value' => $g->value,
                'label' => strtoupper($g->value),
            ])->values()->toArray(),
            'trialDays' => SubscriptionSetting::trialDays(),
            'graceDays' => SubscriptionSetting::gracePeriodDays(),
            't'         => trans('manager_subscriptions'),
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = Subscription::query()
            ->select('subscriptions.*', 'entities.name as entity_name', 'entities.active as entity_active', 'plans.name as plan_name')
            ->join('entities', 'subscriptions.entity_id', '=', 'entities.id')
            ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $lower = mb_strtolower($search, 'UTF-8');
                $inner->whereRaw('LOWER(entities.name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(plans.name) LIKE ?', ["%{$lower}%"]);
            }))
            ->latest('subscriptions.created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => $this->toCardRow($r)),
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
        $subscription->load('entity', 'plan');

        return response()->json(['data' => [
            'id'                      => $subscription->id,
            'entity_id'               => $subscription->entity_id,
            'entity_name'             => $subscription->entity?->name ?? '-',
            'entity_active'           => (bool) ($subscription->entity?->active ?? false),
            'plan_id'                 => $subscription->plan_id,
            'plan_name'               => $subscription->plan?->name ?? '-',
            'status'                  => $subscription->status->value,
            'status_label'            => $subscription->status->label(),
            'status_badge'            => $subscription->status->badgeClass(),
            'billing_state'           => $subscription->billing_state,
            'billing_state_badge'     => $this->billingStateBadge($subscription->billing_state),
            'billing_state_label'     => $this->billingStateLabel($subscription->billing_state),
            'last_billing_error'      => $subscription->last_billing_error,
            'gateway'                 => $subscription->gateway,
            'gateway_customer_id'     => $subscription->gateway_customer_id,
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
            'starts_at'               => $subscription->starts_at?->format('d/m/Y'),
            'starts_at_raw'           => $subscription->starts_at?->format('Y-m-d'),
            'ends_at'                 => $subscription->ends_at?->format('d/m/Y'),
            'ends_at_raw'             => $subscription->ends_at?->format('Y-m-d'),
            'next_billing_at'         => $subscription->next_billing_at?->format('d/m/Y'),
            'next_billing_overdue'    => (bool) ($subscription->next_billing_at?->isPast()),
            'last_payment_at'         => $subscription->last_payment_at?->format('d/m/Y H:i'),
            'trial_ends_at'           => $subscription->trial_ends_at?->format('d/m/Y'),
            'trial_ends_at_raw'       => $subscription->trial_ends_at?->format('Y-m-d'),
            'grace_period_ends_at'    => $subscription->grace_period_ends_at?->format('d/m/Y'),
            'cancelled_at'            => $subscription->cancelled_at?->format('d/m/Y H:i'),
            'created_at'              => $subscription->created_at->format('d/m/Y H:i'),
            'is_accessible'           => $subscription->status->isAccessible(),
        ]]);
    }

    public function invoices(Subscription $subscription): JsonResponse
    {
        $invoices = Invoice::query()
            ->with('payments')
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id'             => $inv->id,
                'reference'      => $inv->reference,
                'status'         => $inv->status->value,
                'status_label'   => $this->invoiceStatusLabel($inv->status->value),
                'status_badge'   => $this->invoiceStatusBadge($inv->status->value),
                'amount'         => number_format((float) $inv->amount, 2, ',', '.'),
                'currency'       => $inv->currency,
                'billing_reason' => $inv->billing_reason,
                'gateway_code'   => $inv->gateway_code,
                'period_start'   => $inv->period_start?->format('d/m/Y'),
                'period_end'     => $inv->period_end?->format('d/m/Y'),
                'due_at'         => $inv->due_at?->format('d/m/Y'),
                'paid_at'        => $inv->paid_at?->format('d/m/Y H:i'),
                'created_at'     => $inv->created_at->format('d/m/Y H:i'),
                'payments'       => $inv->payments->map(fn ($p) => [
                    'id'                  => $p->id,
                    'status'              => $p->status,
                    'status_badge'        => $this->paymentStatusBadge($p->status),
                    'amount'              => number_format((float) $p->amount, 2, ',', '.'),
                    'gateway_code'        => $p->gateway_code,
                    'external_payment_id' => $p->external_payment_id,
                    'paid_at'             => $p->paid_at?->format('d/m/Y H:i'),
                    'failed_at'           => $p->failed_at?->format('d/m/Y H:i'),
                    'created_at'          => $p->created_at->format('d/m/Y H:i'),
                ]),
            ]);

        return response()->json(['data' => $invoices]);
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
                'scheduled_for'  => $r->scheduled_for?->format('d/m/Y H:i'),
                'executed_at'    => $r->executed_at?->format('d/m/Y H:i'),
                'result_message' => $r->result_message,
                'created_at'     => $r->created_at->format('d/m/Y H:i'),
            ]);

        return response()->json(['data' => $retries]);
    }

    public function update(SubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $subscription->update([
            'plan_id'       => $request->plan_id,
            'status'        => $request->status,
            'starts_at'     => $request->starts_at,
            'ends_at'       => $request->ends_at,
            'trial_ends_at' => $request->trial_ends_at,
        ]);

        return response()->json([
            'message' => 'Assinatura atualizada com sucesso.',
            'data'    => $subscription->fresh('plan'),
        ]);
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

        $message = $active ? 'Acesso desbloqueado com sucesso.' : 'Acesso bloqueado com sucesso.';

        return response()->json(['message' => $message]);
    }

    public function activate(Request $request): JsonResponse
    {
        $request->validate([
            'entity_id'     => ['required', 'uuid', 'exists:entities,id'],
            'plan_id'       => ['required', 'uuid', 'exists:plans,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'gateway'       => ['nullable', Rule::in(array_map(static fn (GatewayCode $g) => $g->value, GatewayCode::cases()))],
        ]);

        $entity = Entity::findOrFail($request->entity_id);
        $plan   = Plan::findOrFail($request->plan_id);
        $cycle  = BillingCycle::from($request->billing_cycle);

        $subscription = $this->billingSubscriptionOrchestrator->activateWithGateway(
            entity: $entity,
            plan: $plan,
            cycle: $cycle,
            requestedGateway: $request->input('gateway'),
        );

        return response()->json([
            'message' => 'Assinatura ativada com sucesso.',
            'data'    => $subscription->load('plan', 'currentInvoice'),
        ]);
    }

    public function startTrial(Request $request): JsonResponse
    {
        $request->validate([
            'entity_id' => ['required', 'uuid', 'exists:entities,id'],
            'plan_id'   => ['nullable', 'uuid', 'exists:plans,id'],
            'days'      => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $entity = Entity::findOrFail($request->entity_id);
        $plan   = $request->plan_id ? Plan::findOrFail($request->plan_id) : null;

        $subscription = $this->trialService->startManualTrial($entity, $plan, $request->days);

        return response()->json([
            'message' => 'Trial iniciado com sucesso.',
            'data'    => $subscription->load('plan'),
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

        $entity       = Entity::findOrFail($request->entity_id);
        $subscription = $this->subscriptionService->getCurrent($entity);

        if (! $subscription) {
            return response()->json(['message' => 'Nenhuma assinatura ativa encontrada.'], 404);
        }

        $oldStatus = $subscription->status->value;

        $subscription = $this->billingCancellationService->cancel(
            subscription: $subscription,
            entity: $entity,
            reason: CancellationReason::AdminAction,
            source: 'manager',
            cancelAtGateway: true,
        );

        // Audit estruturado da ação destrutiva, com a justificativa do admin.
        $this->audit->recordAdminAction(
            event: 'manager.subscription.cancel',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'subscription',
            auditableId: (string) $subscription->id,
            reason: trim((string) $request->input('reason')),
            newValues: [
                'cancellation_reason' => CancellationReason::AdminAction->value,
                'cancel_at_gateway'   => true,
                'new_status'          => $subscription->status->value,
            ],
            request: $request,
            oldValues: ['status' => $oldStatus],
        );

        return response()->json([
            'message' => 'Assinatura cancelada com sucesso.',
            'data'    => $subscription->load('plan'),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate([
            'trial_days'        => ['sometimes', 'integer', 'min:1', 'max:365'],
            'grace_period_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ]);

        if ($request->has('trial_days')) {
            SubscriptionSetting::setValue('trial_days', $request->trial_days, 'Duração do período de trial em dias');
        }

        if ($request->has('grace_period_days')) {
            SubscriptionSetting::setValue('grace_period_days', $request->grace_period_days, 'Dias de graça após expiração');
        }

        return response()->json(['message' => 'Configurações atualizadas.']);
    }

    // ── Helpers de apresentação ───────────────────────────────────────────────

    private function toTableRow(Subscription $s): array
    {
        return [
            'id'                  => $s->id,
            'entity_id'           => $s->entity_id,
            'entity_name'         => $s->entity_name,
            'entity_active'       => (bool) $s->entity_active,
            'plan_name'           => $s->plan_name ?? '-',
            'status'              => $s->status->value,
            'status_label'        => $s->status->label(),
            'status_badge'        => $s->status->badgeClass(),
            'billing_state'       => $s->billing_state,
            'billing_state_badge' => $this->billingStateBadge($s->billing_state),
            'billing_state_label' => $this->billingStateLabel($s->billing_state),
            'last_billing_error'  => $s->last_billing_error,
            'gateway'             => $s->gateway,
            'next_billing_at'     => $s->next_billing_at?->format('d/m/Y'),
            'starts_at'           => $s->starts_at?->format('d/m/Y'),
            'ends_at'             => $s->ends_at?->format('d/m/Y'),
            'trial_ends_at'       => $s->trial_ends_at?->format('d/m/Y'),
            'is_accessible'       => $s->status->isAccessible(),
            'needs_attention'     => in_array($s->billing_state, ['past_due', 'chargeback', 'error'], true),
        ];
    }

    private function toCardRow(Subscription $r): array
    {
        return [
            'id'                  => $r->id,
            'entity_id'           => $r->entity_id,
            'entity_name'         => $r->entity_name,
            'entity_active'       => (bool) $r->entity_active,
            'plan_name'           => $r->plan_name ?? '-',
            'status'              => $r->status->value,
            'status_label'        => $r->status->label(),
            'status_badge'        => $r->status->badgeClass(),
            'billing_state'       => $r->billing_state,
            'billing_state_badge' => $this->billingStateBadge($r->billing_state),
            'billing_state_label' => $this->billingStateLabel($r->billing_state),
            'last_billing_error'  => $r->last_billing_error,
            'gateway'             => $r->gateway,
            'next_billing_at'     => $r->next_billing_at?->format('d/m/Y'),
            'starts_at'           => $r->starts_at?->format('d/m/Y'),
            'ends_at'             => $r->ends_at?->format('d/m/Y'),
            'trial_ends_at'       => $r->trial_ends_at?->format('d/m/Y'),
            'is_accessible'       => $r->status->isAccessible(),
            'needs_attention'     => in_array($r->billing_state, ['past_due', 'chargeback', 'error'], true),
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
            default              => 'badge-soft-secondary',
        };
    }

    private function billingStateLabel(?string $state): string
    {
        return match ($state) {
            'paid'               => 'Pago',
            'pending'            => 'Pendente',
            'past_due'           => 'Em atraso',
            'chargeback'         => 'Chargeback',
            'error'              => 'Erro',
            'cancelled'          => 'Cancelado',
            'pending_activation' => 'Ativando...',
            default              => $state ?? '-',
        };
    }

    private function invoiceStatusLabel(string $status): string
    {
        return match ($status) {
            'draft'     => 'Rascunho',
            'pending'   => 'Pendente',
            'paid'      => 'Pago',
            'overdue'   => 'Vencido',
            'failed'    => 'Falhou',
            'refunded'  => 'Reembolsado',
            'cancelled' => 'Cancelado',
            default     => $status,
        };
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
