<?php

namespace App\Http\Controllers;

use App\Models\{Entity, Plan, Subscription};
use App\Services\SubscriptionService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;

class SubscriptionExpiredController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    public function __invoke(): Factory|Application|View
    {
        $entityId = session('selected_entity_id');
        $entity   = $entityId ? Entity::find($entityId) : null;

        // Se a empresa tem acesso, redireciona para o dashboard
        if ($entity && $this->subscriptionService->hasAccess($entity)) {
            return redirect()->route('panel.dashboard');
        }

        // Busca a última assinatura para contexto
        $lastSubscription = $entity
            ? Subscription::forEntity($entity->id)->with('plan')->latest()->first()
            : null;

        // Planos disponíveis para upgrade, ordenados
        $plans = Plan::active()
            ->with('features')
            ->orderBy('sort_order')
            ->get()
            ->map(function (Plan $plan) {
                return [
                    'model'        => $plan,
                    'features_map' => $plan->features->keyBy('feature')->map->value,
                ];
            });

        return view('subscription.expired', compact('entity', 'lastSubscription', 'plans'));
    }
}
