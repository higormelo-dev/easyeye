<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EntityIntegratorResource;
use App\Models\{EntityIntegrator, EntityUserIntegrator};
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
            ['integrator_id:' . $integrator->id],
            Carbon::now()->addDays(7),
        );

        return response()->json(
            (new EntityIntegratorResource($integrator, $token)),
            HttpResponse::HTTP_OK,
        );
    }

    public function checkToken(Request $request): JsonResponse
    {
        $token = $request->get('token');

        if (! $token) {
            return $this->invalidResponse('auth.token_not_provided', HttpResponse::HTTP_BAD_REQUEST);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken) {
            return $this->invalidResponse('auth.token_invalid');
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            $accessToken->delete();

            return $this->invalidResponse('auth.token_expired');
        }

        $integratorId = EntityIntegrator::idFromTokenAbilities($accessToken->abilities);

        if (! $integratorId) {
            $accessToken->delete();

            return $this->invalidResponse('auth.token_invalid');
        }

        $integrator = EntityIntegrator::query()
            ->with('user.entity')
            ->find($integratorId);

        $blockReason = $integrator ? $integrator->accessBlockReason() : 'auth.integrator_inactive';

        if ($blockReason !== null) {
            $accessToken->delete();

            return $this->invalidResponse($blockReason);
        }

        // Verifica se o token vai expirar em 1 dia útil e renova automaticamente
        $renewed   = false;
        $expiresAt = $accessToken->expires_at;

        if ($expiresAt && $this->willExpireInOneBusinessDay($expiresAt)) {
            $accessToken->expires_at = Carbon::now()->addDays(7);
            $accessToken->save();
            $expiresAt = $accessToken->expires_at;
            $renewed   = true;
        }

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
