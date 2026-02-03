<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EntityIntegratorResource;
use App\Models\{EntityIntegrator, EntityUserIntegrator};
use Carbon\Carbon;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Hash;
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
            return response()->json(
                ['message' => __('auth.failed')],
                HttpResponse::HTTP_UNAUTHORIZED
            );
        }

        if (! $user->active) {
            return response()->json(
                ['message' => __('auth.inactive')],
                HttpResponse::HTTP_UNAUTHORIZED
            );
        }

        $integrator = EntityIntegrator::query()
            ->where('entity_user_integrator_id', $user->id)
            ->where('code', $request->get('code'))
            ->first();

        if (! $integrator) {
            return response()->json(
                ['message' => __('auth.integrator_invalid')],
                HttpResponse::HTTP_UNAUTHORIZED
            );
        }

        if (! $integrator->active) {
            return response()->json(
                ['message' => __('auth.integrator_inactive')],
                HttpResponse::HTTP_UNAUTHORIZED
            );
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
}
