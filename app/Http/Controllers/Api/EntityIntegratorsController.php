<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EntityIntegratorResource;
use App\Models\{EntityIntegrator, EntityUserIntegrator};
use Carbon\Carbon;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EntityIntegratorsController extends Controller
{
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

        if ($accessToken->expires_at?->isPast()) {
            return $this->invalidResponse('auth.token_expired');
        }

        $integratorId = $this->extractIntegratorId($accessToken->abilities);

        if (! $integratorId) {
            return $this->invalidResponse('auth.token_invalid');
        }

        $integrator = EntityIntegrator::query()
            ->with('user.entity')
            ->where('id', $integratorId)
            ->where('active', true)
            ->first();

        if (! $integrator) {
            return $this->invalidResponse('auth.integrator_inactive');
        }

        if (! $integrator->user->active) {
            return $this->invalidResponse('auth.user_integrator_inactive');
        }

        if (! $integrator->user->entity?->active) {
            return $this->invalidResponse('auth.entity_inactive');
        }

        return response()->json([
            'message'    => __('auth.token_valid'),
            'valid'      => true,
            'expires_at' => $accessToken->expires_at,
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
        foreach ($abilities as $key => $value) {
            $ability = is_int($key) ? $value : $key;

            if (str_starts_with($ability, 'integrator_id:')) {
                $parts = explode(':', $ability, 2);

                return $parts[1] ?? null;
            }
        }

        return null;
    }

    private function invalidResponse(string $messageKey, int $status = HttpResponse::HTTP_UNAUTHORIZED): JsonResponse
    {
        return response()->json(
            ['message' => __($messageKey), 'valid' => false],
            $status
        );
    }
}
