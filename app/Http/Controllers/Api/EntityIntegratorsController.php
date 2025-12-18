<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EntityIntegrator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Random\RandomException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EntityIntegratorsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected EntityIntegrator $model;

    public function __construct(EntityIntegrator $entityIntegrator)
    {
        $this->model = $entityIntegrator;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $record = $this->model->query()
            ->where('token', $request->get('token'))
            ->where('active', true)
            ->first();

        if (!$record) {
            return response()->json(
                ['message' => 'Integrator not found or inactive.'],
                HttpResponse::HTTP_NOT_FOUND
            );
        }

        $expiresAt    = Carbon::now()->addDays(7);
        $tokenName    = 'integrator-' . $record->id . '-' . now()->timestamp;
        $sanctumToken = $record->createToken($tokenName, ['integrator:access'], $expiresAt);

        $record->update([
            'token_session'            => $sanctumToken->plainTextToken,
            'token_session_expires_at' => $expiresAt,
        ]);
        $record->refresh();

        return response()->json(
            [
                'access_token' => $sanctumToken->plainTextToken,
                'token_type'   => 'Bearer',
                'expires_at'   => $expiresAt->toISOString(),
                'integrator'   => [
                    'id'        => $record->id,
                    'name'      => $record->name,
                    'entity_id' => $record->entity_id,
                ],
            ],
            HttpResponse::HTTP_OK
        );
    }

    /**
     * Revogar token (logout)
     */
    public function destroy(Request $request)
    {
        $user = $request->user();

        if ($user instanceof EntityIntegrator) {
            $user->update([
                'token_session'            => null,
                'token_session_expires_at' => null,
            ]);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Token revoked successfully.',
        ], HttpResponse::HTTP_OK);
    }

    /**
     * @throws RandomException
     */
    private function generateUniqueToken(): string
    {
        do {
            $tokenSession = hash(
                'sha256',
                microtime(true) . random_bytes(32) . uniqid('', true) . mt_rand()
            );
        } while ($this->model->query()->where('token_session', $tokenSession)->exists());

        return $tokenSession;
    }
}
