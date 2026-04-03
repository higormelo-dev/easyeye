<?php

namespace App\Http\Middleware;

use App\Enums\FeatureKey;
use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica se o plano da entidade permite uso da API de integradores.
 *
 * Pré-requisito: executar após auth_with_integrator (que define 'integrator').
 */
class ApiCheckPlanAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $integrator = $request->attributes->get('integrator');

        if (!$integrator) {
            return $next($request);
        }

        $entityId = $integrator->user->entity_id;

        $subscription = Subscription::forEntity($entityId)
            ->accessible()
            ->with('plan.features')
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => __('subscriptions.access_blocked'),
                'valid'   => false,
            ], Response::HTTP_FORBIDDEN);
        }

        // Verifica se o plano permite acesso aos integradores
        $hasAccess = $subscription->plan->featureValue(FeatureKey::HasApiIntegrator) === '1';

        if (!$hasAccess) {
            return response()->json([
                'message' => __('subscriptions.features.plan_upgrade_required'),
                'valid'   => false,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
