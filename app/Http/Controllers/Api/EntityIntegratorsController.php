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
        $user = $this->model->query()
            ->where('email', $request->get('email'))
            ->first();

        if (! $user || ! Hash::check($request->get('password'), $user->password)) {

            return $this->invalidResponse('auth.failed');
        }

        if (! $user->active) {
            return $this->invalidResponse('auth.inactive');
        }

        $integrator = EntityIntegrator::query()
            ->where('entity_user_integrator_id', $user->id)
            ->where('code', $request->get('code'))
            ->where('active', true)
            ->first();

        if (! $integrator) {
            return $this->invalidResponse('auth.integrator_invalid');
        }

        $token = $user->createToken(
            'integrator-token',
            ['integrator_id:' . $integrator->id],
            Carbon::now()->addDay(7)
        );

        return response()->json(
            (new EntityIntegratorResource($integrator, $token)),
            HttpResponse::HTTP_OK
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

        $integratorId = $this->extractIntegratorId($accessToken->abilities);

        if (! $integratorId) {
            $accessToken->delete();

            return $this->invalidResponse('auth.token_invalid');
        }

        $integrator = EntityIntegrator::query()
            ->with('user.entity')
            ->where('id', $integratorId)
            ->where('active', true)
            ->first();

        if (! $integrator) {
            $accessToken->delete();

            return $this->invalidResponse('auth.integrator_inactive');
        }

        if (! $integrator->user->active) {
            $accessToken->delete();

            return $this->invalidResponse('auth.user_integrator_inactive');
        }

        if (! ($integrator->user->entity && $integrator->user->entity->active)) {
            $accessToken->delete();

            return $this->invalidResponse('auth.entity_inactive');
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
     * Revogar token (logout)
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

    private function extractIntegratorId(array $abilities): ?string
    {
        $ability = collect($abilities)
            ->map(fn ($value, $key) => is_int($key) ? $value : $key)
            ->filter(fn (string $item) => str_starts_with($item, 'integrator_id:'))
            ->first();

        if (! $ability) {
            return null;
        }

        return substr($ability, strlen('integrator_id:')) ?: null;
    }

    private function invalidResponse(string $messageKey, int $status = HttpResponse::HTTP_UNAUTHORIZED): JsonResponse
    {
        return response()->json(
            ['message' => __($messageKey), 'valid' => false],
            $status
        );
    }
}
