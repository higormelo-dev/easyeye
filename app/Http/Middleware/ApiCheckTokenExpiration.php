<?php

namespace App\Http\Middleware;

use App\Models\EntityIntegrator;
use App\Traits\HasBusinessDays;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\Response;

class ApiCheckTokenExpiration
{
    use HasBusinessDays;

    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user() ? $request->user()->currentAccessToken() : null;

        if (! $token) {
            return $next($request);
        }

        $now = Carbon::now();

        // Use the original last_used_at captured before Sanctum overwrote it (via ApiTokenPreCheck)
        $lastUsed = $request->attributes->get('token_last_used_at') ?? $token->last_used_at ?? $token->created_at;

        // Calcula dias úteis desde o último uso
        $businessDays = $this->countBusinessDays($lastUsed, $now);

        // Expirou por inatividade (3 dias úteis sem uso)
        if ($businessDays > 3) {
            $token->delete();

            return response()->json([
                'message' => __('auth.token_expired_inactivity'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Renova automaticamente se vai expirar em 1 dia útil ou menos
        if ($token->expires_at && $this->willExpireInOneBusinessDay($token->expires_at)) {
            $token->expires_at = Carbon::now()->addDays(7);
            $token->save();
        }

        // auth_with_integrator (quando presente na pipeline, como nas rotas v1)
        // já carregou e validou o integrador nesta request — não revalida nem re-consulta.
        if ($request->attributes->has('integrator')) {
            return $next($request);
        }

        // Fallback para uso standalone do middleware (sem auth_with_integrator antes)
        $integratorId = EntityIntegrator::idFromTokenAbilities($token->abilities ?? []);

        if ($integratorId) {
            $integrator = EntityIntegrator::query()
                ->with('user.entity')
                ->find($integratorId);

            $blockReason = $integrator ? $integrator->accessBlockReason() : 'auth.integrator_inactive';

            if ($blockReason !== null) {
            if (! $integrator) {
                $token->delete();

                return $this->invalidResponse($blockReason);
                return $this->invalidResponse('auth.integrator_inactive');
            }

            if (! $integrator->user->active) {
                $token->delete();

                return $this->invalidResponse('auth.user_integrator_inactive');
            }

            if (! ($integrator->user->entity && $integrator->user->entity->active)) {
                $token->delete();

                return $this->invalidResponse('auth.entity_inactive');
            }

            // Disponibiliza o integrador para uso posterior na request
            $request->attributes->set('integrator', $integrator);
        }

        return $next($request);
    }

    /**
     * Retorna resposta de erro padronizada.
     */
    private function invalidResponse(string $messageKey, int $status = Response::HTTP_UNAUTHORIZED): JsonResponse
    {
        return response()->json(
            ['message' => __($messageKey), 'valid' => false],
            $status,
        );
    }
}
