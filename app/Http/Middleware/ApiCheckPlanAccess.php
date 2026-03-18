<?php

namespace App\Http\Middleware;

use App\Enums\FeatureKey;
use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica se o plano da entidade permite uso da API de integradores
 * e define o limite de per_page conforme o plano contratado.
 *
 * Define no request:
 *   - api_per_page_limit (int): limite máximo de registros por página.
 *
 * Pré-requisito: executar após auth_with_integrator (que define 'integrator').
 */
class ApiCheckPlanAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $integrator = $request->attributes->get('integrator');

        if (! $integrator) {
            return $next($request);
        }

        $entityId = $integrator->user->entity_id;

        $subscription = Subscription::forEntity($entityId)
            ->accessible()
            ->with('plan.features')
            ->first();

        if (! $subscription) {
            return response()->json([
                'message' => __('subscriptions.access_blocked'),
                'valid'   => false,
            ], Response::HTTP_FORBIDDEN);
        }

        // Verifica se o plano permite acesso aos integradores
        $hasAccess = $subscription->plan->featureValue(FeatureKey::HasApiIntegrator) === '1';

        if (! $hasAccess) {
            return response()->json([
                'message' => __('subscriptions.features.plan_upgrade_required'),
                'valid'   => false,
            ], Response::HTTP_FORBIDDEN);
        }

        // Define o limite de per_page baseado no plano (0 = ilimitado → cap interno de 1000)
        $rawLimit     = $subscription->plan->featureValue(FeatureKey::ApiPerPageLimit);
        $perPageLimit = ($rawLimit !== null && (int) $rawLimit === 0) ? 1000 : (int) ($rawLimit ?? 100);

        $request->attributes->set('api_per_page_limit', $perPageLimit);

        return $next($request);
    }
}
