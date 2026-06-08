<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Entity, Plan, Subscription};
use App\Services\SubscriptionService;
use BackedEnum;
use Illuminate\Http\RedirectResponse;
use Inertia\{Inertia, Response as InertiaResponse};

class SubscriptionExpiredController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    public function __invoke(): InertiaResponse|RedirectResponse
    {
        $entityId = session('selected_entity_id');
        $entity   = $entityId ? Entity::find($entityId) : null;

        // Se a empresa tem acesso, redireciona para o dashboard
        if ($entity && $this->subscriptionService->hasAccess($entity)) {
            return redirect()->route('panel.dashboard');
        }

        $lastSubscription = $entity
            ? Subscription::forEntity($entity->id)->with('plan')->latest()->first()
            : null;

        $plans = Plan::active()
            ->with('features')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => [
                'id'            => $plan->id,
                'name'          => $plan->name,
                'description'   => $plan->description,
                'price'         => (float) $plan->price,
                'billing_cycle' => $plan->billing_cycle instanceof BackedEnum
                    ? $plan->billing_cycle->value
                    : $plan->billing_cycle,
                'features_map' => $plan->features->keyBy('feature')->map->value,
            ]);

        return Inertia::render('Panel/SubscriptionExpired', [
            'entity' => $entity ? [
                'id'   => (string) $entity->id,
                'name' => $entity->name,
            ] : null,
            'lastSubscription' => $lastSubscription ? [
                'plan_name' => $lastSubscription->plan?->name,
                'ends_at'   => $lastSubscription->ends_at?->format('d/m/Y'),
                'status'    => $lastSubscription->status instanceof BackedEnum
                    ? $lastSubscription->status->value
                    : $lastSubscription->status,
            ] : null,
            'plans' => $plans,
            'urls'  => [
                'logout' => route('logout'),
            ],
        ]);
    }
}
