<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Models\Entity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Inertia\{Inertia, Response as InertiaResponse};

class SubscriptionExpiredController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function __invoke(): InertiaResponse|RedirectResponse
    {
        $entityId = session('selected_entity_id');
        $entity   = $entityId ? Entity::find($entityId) : null;

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
            ->map(function (Plan $plan) {
                $featuresMap = $plan->features->keyBy('feature')->map->value;

                return [
                    'id'            => $plan->id,
                    'name'          => $plan->name,
                    'description'   => $plan->description,
                    'price'         => number_format($plan->price, 2, ',', '.'),
                    'billing_cycle' => $plan->billing_cycle->label(),
                    'sort_order'    => $plan->sort_order,
                    'is_popular'    => $plan->sort_order === 2,
                    'features'      => collect(FeatureKey::cases())
                        ->map(fn ($fk) => [
                            'key'        => $fk->value,
                            'label'      => $fk->label(),
                            'is_boolean' => $fk->isBoolean(),
                            'value'      => $featuresMap[$fk->value] ?? null,
                        ])
                        ->filter(fn ($f) => $f['value'] !== null)
                        ->values(),
                ];
            });

        return Inertia::render('Subscription/Expired', [
            'lastSubscription' => $lastSubscription ? [
                'plan_name'            => $lastSubscription->plan->name,
                'status_label'         => $lastSubscription->status->label(),
                'in_grace_period'      => $lastSubscription->inGracePeriod(),
                'grace_period_ends_at' => $lastSubscription->grace_period_ends_at?->format('d/m/Y H:i'),
            ] : null,
            'plans'        => $plans,
            'supportEmail' => config('mail.support_address', 'contato@medicare.com.br'),
        ]);
    }
}
