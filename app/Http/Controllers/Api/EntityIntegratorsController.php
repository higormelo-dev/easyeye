<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivationStep;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EntityIntegratorResource;
use App\Models\{EntityIntegrator, EntityUserIntegrator};
use App\Services\{ActivationService, IntegratorTokenPolicy};
use App\Traits\HasBusinessDays;
use Carbon\Carbon;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EntityIntegratorsController extends Controller
{
    use HasBusinessDays;

    /**
     * Hash bcrypt (cost 12, igual a BCRYPT_ROUNDS) de valor aleatório descartado.
     * Quando o e-mail não existe, validamos a senha contra este hash para que o
     * tempo de resposta seja o mesmo de uma senha errada — evita enumeração de
     * e-mails por timing.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$/OdW0afyfIPKXjw1pxgP/.RibSsBgr4figSpx4NSCS.ExmqC4rozK';

    /**
     * Instance of the standard model.
     */
    protected EntityUserIntegrator $model;

    public function __construct(EntityUserIntegrator $entityUserIntegrator)
    {
        $this->model = $entityUserIntegrator;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string'],
            'password' => ['required', 'string'],
            'code'     => ['required', 'string'],
            // Escopo opcional do token. Ausente = acesso total (read+write),
            // preservando o comportamento dos clientes que não enviam o campo.
            'scope'           => ['sometimes', 'string', 'in:read,write'],
            'installation_id' => ['nullable', 'uuid'],
        ]);

        $user = $this->model->query()
            ->where('email', $credentials['email'])
            ->first();

        // Hash::check roda SEMPRE (mesmo sem usuário) — ver DUMMY_PASSWORD_HASH.
        $passwordValid = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_PASSWORD_HASH);

        if (! $user || ! $passwordValid) {
            return $this->invalidResponse('auth.failed');
        }

        if (! $user->active) {
            return $this->invalidResponse('auth.inactive');
        }

        if (! ($user->entity && $user->entity->active)) {
            return $this->invalidResponse('auth.entity_inactive');
        }

        $integrator = EntityIntegrator::query()
            ->where('entity_user_integrator_id', $user->id)
            ->where('code', EntityIntegrator::normalizeCode($credentials['code']))
            ->where('active', true)
            ->first();

        if (! $integrator) {
            return $this->invalidResponse('auth.integrator_invalid');
        }

        // Housekeeping: remove tokens já expirados deste usuário para a tabela
        // não acumular lixo a cada novo signin do cliente desktop.
        $user->tokens()->where('expires_at', '<', Carbon::now())->delete();

        $token = $user->createToken(
            'integrator-token',
            app(IntegratorTokenPolicy::class)->abilities($integrator, $credentials['scope'] ?? null, $credentials['installation_id'] ?? null),
            Carbon::now()->addDays(7),
        );

        app(ActivationService::class)->complete($user->entity_id, ActivationStep::IntegratorConnected);

        return response()->json(
            (new EntityIntegratorResource($integrator, $token)),
            HttpResponse::HTTP_OK,
        );
    }

    /**
     * Monta as abilities do token Sanctum:
     * - `integrator_id:<uuid>` sempre presente (identifica o integrador).
     * - `api:read` / `api:write` conforme o escopo solicitado:
     *     - 'read'  → somente leitura
     *     - 'write' → leitura + escrita
     *     - ausente → leitura + escrita (padrão, retrocompatível)
     *
     * EnsureTokenScope usa essas abilities para barrar escrita com token read-only.
     *
     * @return list<string>
     */
    private function abilitiesFor(string $integratorId, ?string $scope): array
    {
        $abilities = ['integrator_id:' . $integratorId];

        return match ($scope) {
            'read'  => [...$abilities, 'api:read'],
            default => [...$abilities, 'api:read', 'api:write'],
        };
    }

    public function checkToken(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:512']]);

        if (! $request->request->has('token')) {
            return response()->json(['code' => 'token_body_required', 'valid' => false], 422);
        }
        $accessToken = PersonalAccessToken::findToken($validated['token']);

        if (! $accessToken) {
            return $this->invalidResponse('auth.token_invalid');
        }
        $policy = app(IntegratorTokenPolicy::class);

        if ($reason = $policy->reason($accessToken)) {
            $accessToken->delete();

            return $this->invalidResponse($reason);
        }
        $renewed   = $policy->renew($accessToken);
        $expiresAt = $accessToken->expires_at;

        return response()->json([
            'message'    => $renewed ? __('auth.token_renewed') : __('auth.token_valid'),
            'valid'      => true,
            'renewed'    => $renewed,
            'expires_at' => $expiresAt,
        ], HttpResponse::HTTP_OK);
    }

    /**
     * Revogar token (logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        // Remove os atributos definidos no middleware
        $request->attributes->remove('user');
        $request->attributes->remove('integrator');

        return response()->json([
            'message' => 'Token revoked successfully.',
        ], HttpResponse::HTTP_OK);
    }

    private function invalidResponse(string $messageKey, int $status = HttpResponse::HTTP_UNAUTHORIZED): JsonResponse
    {
        return response()->json(
            ['message' => __($messageKey), 'valid' => false],
            $status,
        );
    }
}
