<?php

namespace App\Http\Controllers\Manager;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\SubscriptionRequest;
use App\Models\Entity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSetting;
use App\Services\SubscriptionService;
use App\Services\TrialService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SubscriptionsController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly TrialService $trialService,
    ) {}

    public function index(): \Inertia\Response
    {
        $plans = Plan::active()->orderBy('sort_order')->get(['id', 'name', 'billing_cycle']);

        $billingCycles = collect(BillingCycle::cases())->map(fn ($c) => [
            'value' => $c->value,
            'label' => $c->label(),
        ]);

        $statuses = collect(SubscriptionStatus::cases())->map(fn ($s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'badge' => $s->badgeClass(),
        ]);

        return Inertia::render('Manager/Subscriptions/Index', [
            'plans'         => $plans,
            'billingCycles' => $billingCycles,
            'statuses'      => $statuses,
            'trialDays'     => SubscriptionSetting::trialDays(),
            'graceDays'     => SubscriptionSetting::gracePeriodDays(),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $status  = $request->string('status')->trim()->value();
        $perPage = 15;

        $subscriptions = Subscription::query()
            ->with(['entity', 'plan'])
            ->when($search, function ($q) use ($search) {
                $lower = mb_strtolower($search, 'UTF-8');
                $q->whereHas('entity', function ($eq) use ($lower) {
                    $eq->whereRaw('LOWER(name) LIKE ?', ["%{$lower}%"])
                       ->orWhereRaw('LOWER(code) LIKE ?', ["%{$lower}%"]);
                });
            })
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $subscriptions->map(fn (Subscription $s) => [
            'id'            => $s->id,
            'entity_id'     => $s->entity_id,
            'entity_name'   => $s->entity?->name,
            'entity_code'   => $s->entity?->code,
            'entity_active' => (bool) $s->entity?->active,
            'plan_id'       => $s->plan_id,
            'plan_name'     => $s->plan?->name ?? '—',
            'status'        => $s->status->value,
            'status_label'  => $s->status->label(),
            'status_badge'  => $s->status->badgeClass(),
            'starts_at'     => $s->starts_at?->format('d/m/Y'),
            'ends_at'       => $s->ends_at?->format('d/m/Y'),
            'trial_ends_at' => $s->trial_ends_at?->format('d/m/Y'),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $subscriptions->total(),
                'per_page'     => $subscriptions->perPage(),
                'current_page' => $subscriptions->currentPage(),
                'last_page'    => $subscriptions->lastPage(),
            ],
        ]);
    }

    public function show(Subscription $subscription): \Inertia\Response|JsonResponse
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

        return Inertia::render('Manager/Subscriptions/Show', [
            'subscription' => $subscription,
        ]);
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
        ]);

        $entity = Entity::findOrFail($request->entity_id);
        $plan   = Plan::findOrFail($request->plan_id);
        $cycle  = BillingCycle::from($request->billing_cycle);

        $subscription = $this->subscriptionService->activate($entity, $plan, $cycle);

        return response()->json([
            'message' => 'Assinatura ativada com sucesso.',
            'data'    => $subscription->load('plan'),
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
        $subscription = $this->subscriptionService->cancel($entity);

        if (! $subscription) {
            return response()->json(['message' => 'Nenhuma assinatura ativa encontrada.'], 404);
        }

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
}
