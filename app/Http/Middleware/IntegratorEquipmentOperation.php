<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IntegratorEquipmentOperation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('integrators.v1.equipments.store', 'integrators.v1.equipments.update') || ! $request->has('operation_id')) {
            return $next($request);
        }
        $valid = $request->validate(['operation_id' => ['required', 'uuid'], 'installation_id' => ['required', 'uuid'], 'expected_config_generation' => $request->isMethod('PUT') || $request->isMethod('PATCH') ? ['required', 'integer', 'min:1'] : ['prohibited']]);

        if ($request->header('Idempotency-Key') !== $valid['operation_id']) {
            return response()->json(['code' => 'equipment_operation_key_invalid'], 422);
        }

        if (strlen($request->getContent()) > 16384) {
            return response()->json(['code' => 'equipment_operation_too_large'], 422);
        }
        $integrator = $request->attributes->get('integrator');
        $actor      = $request->user()->id;

        foreach ((array) $request->user()->currentAccessToken()->abilities as $ability) {
            if (str_starts_with($ability, 'installation_id:') && substr($ability, 16) !== $valid['installation_id']) {
                return response()->json(['code' => 'installation_scope_mismatch'], 403);
            }
        }
        $payload = $request->all();
        ksort($payload);
        $fingerprint = hash('sha256', json_encode([$request->method(), $request->path(), config('app.url'), $integrator->user->entity_id, $integrator->id, $actor, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($request, $next, $integrator, $actor, $valid, $fingerprint) {
            if (DB::getDriverName() === 'pgsql' && ! DB::selectOne('select pg_try_advisory_xact_lock(hashtext(?)) as acquired', ['equipment:' . $integrator->id])->acquired) {
                return response()->json(['code' => 'equipment_operation_in_progress'], 409)->header('Retry-After', '5');
            }
            $receipt = DB::table('integrator_equipment_operations')->where('integrator_id', $integrator->id)->where('operation_id', $valid['operation_id'])->first();

            if ($receipt) {
                if (! hash_equals($receipt->fingerprint, $fingerprint) || $receipt->actor_id !== $actor) {
                    return response()->json(['code' => 'equipment_operation_payload_conflict'], 409);
                }

                return response(Crypt::decryptString($receipt->response), $receipt->status, ['Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true']);
            }
            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $body         = json_decode($response->getContent(), true, 32, JSON_THROW_ON_ERROR);
                $body['meta'] = ['operation_id' => $valid['operation_id'], 'config_generation' => $body['data']['attributes']['config_generation'] ?? 1];
                $response->setContent(json_encode($body, JSON_THROW_ON_ERROR));
                DB::table('integrator_equipment_operations')->insert(['id' => (string) Str::uuid(), 'integrator_id' => $integrator->id, 'actor_id' => $actor, 'installation_id' => $valid['installation_id'], 'operation_id' => $valid['operation_id'], 'fingerprint' => $fingerprint, 'status' => $response->getStatusCode(), 'response' => Crypt::encryptString($response->getContent()), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $response;
        });
    }
}
