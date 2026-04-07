<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\SubscriptionsDataTable;
use App\Enums\Billing\{CancellationReason, GatewayCode};
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\SubscriptionRequest;
use App\Models\Billing\{BillingRetrySchedule, Invoice};
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting};
use App\Services\Billing\{BillingCancellationService, BillingSubscriptionOrchestrator};
use App\Services\{SubscriptionService, TrialService};
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class SubscriptionsController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly TrialService $trialService,
        private readonly BillingSubscriptionOrchestrator $billingSubscriptionOrchestrator,
        private readonly BillingCancellationService $billingCancellationService,
    ) {
    }

    public function index(SubscriptionsDataTable $dataTable): Factory|Application|View|JsonResponse
    {
        $total = Subscription::count();

        $meta = [
            'title'            => 'Assinaturas',
            'action'           => __('actions.records'),
            'total'            => $total,
            'cardsUrl'         => route('panel.manager.subscriptions.cards'),
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Assinaturas', 'url' => route('panel.manager.subscriptions.index'), 'active' => false],
                ['label' => __('actions.records'), 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        $plans         = Plan::active()->orderBy('sort_order')->get();
        $billingCycles = BillingCycle::cases();
        $statuses      = SubscriptionStatus::cases();
        $gateways      = GatewayCode::cases();
        $trialDays     = SubscriptionSetting::trialDays();
        $graceDays     = SubscriptionSetting::gracePeriodDays();
        $baseUrl       = url('panel/manager/subscriptions');

        return $dataTable->render('system.manager.subscriptions.index', compact(
            'meta',
            'plans',
            'billingCycles',
            'statuses',
            'gateways',
            'trialDays',
            'graceDays',
            'baseUrl',
        ));
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
            'data' => $records->map(fn ($r) => [
                'id'                 => $r->id,
                'entity_id'          => $r->entity_id,
                'entity_name'        => $r->entity_name,
                'entity_active'      => (bool) $r->entity_active,
                'plan_name'          => $r->plan_name ?? '-',
                'status'             => $r->status->value,
                'status_label'       => $r->status->label(),
                'status_badge'       => $r->status->badgeClass(),
                'billing_state'      => $r->billing_state,
                'billing_state_badge'=> $this->billingStateBadge($r->billing_state),
                'billing_state_label'=> $this->billingStateLabel($r->billing_state),
                'last_billing_error' => $r->last_billing_error,
                'gateway'            => $r->gateway,
                'next_billing_at'    => $r->next_billing_at?->format('d/m/Y'),
                'starts_at'          => $r->starts_at?->format('d/m/Y'),
                'ends_at'            => $r->ends_at?->format('d/m/Y'),
                'trial_ends_at'      => $r->trial_ends_at?->format('d/m/Y'),
                'is_accessible'      => $r->status->isAccessible(),
                'needs_attention'    => in_array($r->billing_state, ['past_due', 'chargeback', 'error'], true),
            ]),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function show(Subscription $subscription): \Illuminate\View\View|JsonResponse
    {
        $subscription->load('entity', 'plan');

        if (request()->wantsJson()) {
            return response()->json(['data' => [
                'plan_id'       => $subscription->plan_id,
                'status'        => $subscription->status->value,
                'starts_at'     => $subscription->starts_at?->format('Y-m-d'),
                'ends_at'       => $subscription->ends_at?->format('Y-m-d'),
                'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d'),
            ]]);
        }

        return view('system.manager.subscriptions.show', compact('subscription'));
    }

    /**
     * Histórico de faturas e pagamentos de uma assinatura.
     */
    public function invoices(Subscription $subscription): JsonResponse
    {
        $invoices = Invoice::query()
            ->with('payments')
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id'              => $inv->id,
                'reference'       => $inv->reference,
                'status'          => $inv->status->value,
                'status_label'    => $this->invoiceStatusLabel($inv->status->value),
                'status_badge'    => $this->invoiceStatusBadge($inv->status->value),
                'amount'          => number_format((float) $inv->amount, 2, ',', '.'),
                'currency'        => $inv->currency,
                'billing_reason'  => $inv->billing_reason,
                'gateway_code'    => $inv->gateway_code,
                'period_start'    => $inv->period_start?->format('d/m/Y'),
                'period_end'      => $inv->period_end?->format('d/m/Y'),
                'due_at'          => $inv->due_at?->format('d/m/Y'),
                'paid_at'         => $inv->paid_at?->format('d/m/Y H:i'),
                'created_at'      => $inv->created_at->format('d/m/Y H:i'),
                'payments'        => $inv->payments->map(fn ($p) => [
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

    /**
     * Retentativas agendadas de cobrança de uma assinatura.
     */
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
        ]);

        $entity = Entity::findOrFail($request->entity_id);
        $active = $request->boolean('active');

        $entity->update(['active' => $active]);
        $entity->entityUsers()->update(['active' => $active]);

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
        ]);

        $entity       = Entity::findOrFail($request->entity_id);
        $subscription = $this->subscriptionService->getCurrent($entity);

        if (! $subscription) {
            return response()->json(['message' => 'Nenhuma assinatura ativa encontrada.'], 404);
        }

        $subscription = $this->billingCancellationService->cancel(
            subscription: $subscription,
            entity: $entity,
            reason: CancellationReason::AdminAction,
            source: 'manager',
            cancelAtGateway: true,
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
            'draft'    => 'Rascunho',
            'pending'  => 'Pendente',
            'paid'     => 'Pago',
            'overdue'  => 'Vencido',
            'failed'   => 'Falhou',
            'refunded' => 'Reembolsado',
            'cancelled'=> 'Cancelado',
            default    => $status,
        };
    }

    private function invoiceStatusBadge(string $status): string
    {
        return match ($status) {
            'paid'     => 'badge-soft-success',
            'pending'  => 'badge-soft-warning',
            'overdue'  => 'badge-soft-orange',
            'failed'   => 'badge-soft-danger',
            'refunded' => 'badge-soft-info',
            'cancelled'=> 'badge-soft-secondary',
            default    => 'badge-soft-secondary',
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
            'pending'  => 'badge-soft-warning',
            'executed' => 'badge-soft-success',
            'skipped'  => 'badge-soft-secondary',
            'cancelled'=> 'badge-soft-secondary',
            default    => 'badge-soft-secondary',
        };
    }
}
