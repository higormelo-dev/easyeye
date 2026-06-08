<?php

namespace App\Http\Middleware;

use App\Models\Entity;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * Bloqueia acesso quando a empresa não possui assinatura ativa nem período de graça.
     * Empresas que não são clientes (is_client=false) são sempre liberadas.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $entityId = session('selected_entity_id');

        if (! $entityId) {
            return $next($request);
        }

        $entity = Entity::find($entityId);

        // Entidades não-cliente (equipe interna/manager) não precisam de assinatura
        if (! $entity || ! $entity->is_client) {
            return $next($request);
        }

        if (! $this->subscriptionService->hasAccess($entity)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('subscriptions.access_blocked'),
                ], 402);
            }

            return redirect()->route('subscription.expired')
                ->with('warning', __('subscriptions.access_blocked'));
        }

        return $next($request);
    }
}
