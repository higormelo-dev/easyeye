<?php

namespace App\Http\Middleware;

use App\Models\EntityIntegrator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticateWithIntegrator
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user  = $request->user();
        $token = $user ? $user->currentAccessToken() : null;

        if (! $user) {
            return response()->json(['message' => __('http-statuses.401')], 401);
        }

        $integratorId = EntityIntegrator::idFromTokenAbilities($token ? $token->abilities : []);

        if (! $integratorId) {
            return response()->json(['message' => __('http-statuses.401')], 401);
        }

        $integrator = EntityIntegrator::query()
            ->with(['user', 'user.entity'])
            ->find($integratorId);

        // Defesa em profundidade: reafirma a cada request que o integrador da
        // ability pertence mesmo ao usuário autenticado pelo token. Essa
        // amarração só é garantida hoje no momento da EMISSÃO do token
        // (EntityIntegratorsController::store() filtra por
        // entity_user_integrator_id === $user->id antes de gravar a ability
        // integrator_id:<uuid>) — nenhuma leitura subsequente reafirmava a
        // invariante. Sem esta checagem, um integrator_id de outro tenant
        // acabar numa ability (bug futuro de emissão/renovação de token,
        // reordenação de middleware, etc.) seria aceito sem checagem: todo
        // controller/service da API do integrador confia cegamente em
        // request()->attributes->get('integrator')->user->entity_id para
        // escopar patients/exams/equipments — seria IDOR cross-tenant total.
        if ($integrator && $integrator->entity_user_integrator_id !== $user->id) {
            $integrator = null;
        }

        $blockReason = $integrator ? $integrator->accessBlockReason() : 'auth.integrator_inactive';

        if ($blockReason !== null) {
            return response()->json(['message' => __($blockReason), 'valid' => false], 401);
        }

        // Disponibiliza o usuário e integrador para toda a request
        $request->attributes->set('user', $user);
        $request->attributes->set('integrator', $integrator);

        return $next($request);
    }
}
