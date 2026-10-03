<?php

namespace App\Http\Middleware;

use App\Models\{EntityIntegrator, PatientExam};
use Closure;
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Receipt, mutation and quota commit in the same database transaction. */
class ApiIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || $request->isMethodSafe()) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $key)) {
            return response()->json(['code' => 'idempotency_key_invalid', 'message' => __('auth.idempotency_key_invalid')], 400);
        }
        $integrator = $request->attributes->get('integrator');
        abort_unless($integrator !== null, 401);
        $scope   = hash('sha256', implode('|', [$integrator->user->entity_id, $integrator->id, $request->method(), $request->path(), $key]));
        $payload = $request->except(array_keys($request->allFiles()));

        foreach ($request->allFiles() as $field => $file) {
            abort_unless($file instanceof UploadedFile && $file->isValid(), 422);
            $payload[$field] = ['sha256' => hash_file('sha256', $file->getRealPath()), 'bytes' => $file->getSize(), 'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()];
        }
        $fingerprint = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));
        DB::beginTransaction();

        try {
            // No expiring lease: the PostgreSQL transaction owns this lock until
            // commit/rollback, including a slow multipart upload.
            if (DB::getDriverName() === 'pgsql') {
                $parts = unpack('Nfirst/Nsecond', hex2bin(substr($scope, 0, 16)));
                $a     = $parts['first'] > 0x7FFFFFFF ? $parts['first'] - 0x100000000 : $parts['first'];
                $b     = $parts['second'] > 0x7FFFFFFF ? $parts['second'] - 0x100000000 : $parts['second'];

                if (! DB::selectOne('select pg_try_advisory_xact_lock(?, ?) as acquired', [$a, $b])->acquired) {
                    DB::rollBack();

                    return response()->json(['code' => 'idempotency_in_progress', 'message' => __('auth.idempotency_in_progress')], 409)->header('Retry-After', '5');
                }
            }
            DB::table('integrator_api_receipts')->insertOrIgnore(['scope_key' => $scope, 'fingerprint' => $fingerprint,
                'integrator_id'                                               => $integrator->id, 'capture_id' => $request->input('capture_id') === $key && Str::isUuid($key) ? $key : null,
                'created_at'                                                  => now(), 'updated_at' => now()]);
            $receipt = DB::table('integrator_api_receipts')->where('scope_key', $scope)->lockForUpdate()->first();

            if (! hash_equals($receipt->fingerprint, $fingerprint)) {
                DB::rollBack();

                return response()->json(['code' => 'idempotency_payload_conflict', 'message' => 'A chave identifica outra aquisição ou contexto clínico.'], 409);
            }

            if ($receipt->status !== null) {
                $response = response(Crypt::decryptString($receipt->response), $receipt->status)->header('Content-Type', $receipt->content_type)->header('Idempotency-Replayed', 'true');
                DB::commit();

                return $response;
            }

            if ($request->input('capture_id') === $key && Str::isUuid($key)) {
                // Global capture identity crosses endpoint-specific receipts.
                // Serialize proof checks with creation/reconciliation in this
                // integrator, so another endpoint cannot commit this capture
                // between the "absent" check and our rollback.
                EntityIntegrator::whereKey($integrator->id)->lockForUpdate()->firstOrFail();
            }
            $response = $next($request);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $canProve = $response->getStatusCode() === 422 && $this->captureAbsent($request, $key, (string) $integrator->id);
                DB::rollBack();

                return $canProve
                    ? $this->rollbackProof($response, $key, (string) $integrator->id)
                    : $response;
            }
            DB::table('integrator_api_receipts')->where('scope_key', $scope)->update([
                'status'       => $response->getStatusCode(), 'response' => Crypt::encryptString($response->getContent()),
                'content_type' => $response->headers->get('Content-Type', 'application/json'), 'updated_at' => now(),
            ]);
            DB::commit();

            return $response;
        } catch (Throwable $error) {
            $canProve = $error instanceof ValidationException && ($error->response?->getStatusCode() ?? $error->status) === 422
                && $this->captureAbsent($request, $key, (string) $integrator->id);
            DB::rollBack();

            // Only a definitive validation failure after our transaction was
            // rolled back permits editing a previously frozen upload intent.
            // Replays/conflicts return above and can never carry this proof.
            if ($canProve) {
                $response = $error->response ?? response()->json([
                    'message' => $error->getMessage(), 'errors' => $error->errors(),
                ], 422);
                $error->response = $this->rollbackProof($response, $key, (string) $integrator->id);
            }

            throw $error;
        }
    }

    private function captureAbsent(Request $request, string $key, string $integrator): bool
    {
        return $request->input('capture_id') === $key && Str::isUuid($key)
            && ! PatientExam::where('capture_integrator_id', $integrator)->where('capture_id', $key)->exists()
            && ! DB::table('integrator_api_receipts')->where('integrator_id', $integrator)->where('capture_id', $key)->whereNotNull('status')->exists();
    }

    private function rollbackProof(Response $response, string $key, string $integrator): Response
    {
        $response->headers->set('Idempotency-Outcome', 'rolled-back');
        $response->headers->set('Idempotency-Key', $key);
        $response->headers->set('Integrator-Id', $integrator);

        return $response;
    }

    private function canonical(array $value): array
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
