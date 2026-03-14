<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\SubscriptionsDataTable;
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
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class SubscriptionsController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly TrialService $trialService,
    ) {}

    public function index(SubscriptionsDataTable $dataTable): Factory|Application|View|JsonResponse
    {
        $meta = [
            'title'       => 'Assinaturas',
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Assinaturas', 'url' => route('panel.manager.subscriptions.index'), 'active' => false],
                ['label' => __('actions.records'), 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        $plans         = Plan::active()->orderBy('sort_order')->get();
        $billingCycles = BillingCycle::cases();
        $statuses      = SubscriptionStatus::cases();
        $trialDays     = SubscriptionSetting::trialDays();
        $graceDays     = SubscriptionSetting::gracePeriodDays();
        $baseUrl       = url('panel/manager/subscriptions');

        return $dataTable->render('system.manager.subscriptions.index', compact(
            'meta', 'plans', 'billingCycles', 'statuses', 'trialDays', 'graceDays', 'baseUrl'
        ));
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
