<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReceiptReconciliationRequest;
use App\Http\Resources\PatientExamResource;
use App\Models\{EntityIntegrator, PatientExam};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;

/** Bind legacy evidence only after scoped clinical context and original bytes agree. */
class ReceiptReconciliationController extends Controller
{
    public function store(ReceiptReconciliationRequest $request, string $exam): PatientExamResource
    {
        abort_unless(Str::isUuid($exam), 404);
        $integrator = $request->attributes->get('integrator');
        $record     = DB::transaction(function () use ($request, $integrator, $exam) {
            EntityIntegrator::whereKey($integrator->id)->lockForUpdate()->firstOrFail();
            $record = PatientExam::whereKey($exam)
                ->whereHas('patient', fn ($q) => $q->where('entity_id', $integrator->user->entity_id))
                ->whereHas('equipment', fn ($q) => $q->where('integrator_id', $integrator->id))
                ->lockForUpdate()->firstOrFail();
            $expected = [
                'patient_identifier'   => $record->patient_id,
                'schedule_identifier'  => $record->schedule_id,
                'exam_identifier'      => $record->exam_id,
                'equipment_identifier' => $record->entity_integrator_equipment_id,
                'laterality'           => $record->laterality,
            ];

            foreach ($expected as $field => $value) {
                $input = $request->input($field);

                if ($field === 'laterality' && $input !== null) {
                    $input = (int) $input;
                }

                if ($input !== $value) {
                    $this->conflict('receipt_clinical_context_mismatch');
                }
            }

            if (! $record->active || $record->archive === null) {
                $this->conflict('receipt_original_unavailable');
            }

            if ($request->filled('exam_performed_at')) {
                $input     = (string) $request->input('exam_performed_at');
                $performed = $record->exam_performed_at;
                $matches   = $performed !== null && (strlen($input) === 10
                    ? $performed->format('Y-m-d') === $input
                    : $performed->equalTo(Carbon::parse($input)));

                if (! $matches) {
                    $this->conflict('receipt_capture_date_mismatch');
                }
            }

            if ($record->capture_id !== null && ($record->capture_id !== $request->capture_id || $record->capture_integrator_id !== $integrator->id)) {
                $this->conflict('receipt_capture_conflict');
            }
            $collision = PatientExam::where('capture_integrator_id', $integrator->id)->where('capture_id', $request->capture_id)->whereKeyNot($record->id)->exists()
                || ($record->capture_id === null && DB::table('integrator_api_receipts')->where('integrator_id', $integrator->id)->where('capture_id', $request->capture_id)->whereNotNull('status')->exists());

            if ($collision) {
                $this->conflict('receipt_capture_conflict');
            }

            // Always verify the real referenced original, including already bound rows.
            // An invalid receipt must never be generated from stored metadata alone.
            $stream = Storage::disk('s3')->readStream($record->archive);

            if (! is_resource($stream)) {
                $this->conflict('receipt_original_unavailable');
            }
            $hash  = hash_init('sha256');
            $bytes = 0;

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);

                    if ($chunk === false) {
                        $this->conflict('receipt_original_unavailable');
                    }
                    $bytes += strlen($chunk);

                    if ($bytes > 10485760) {
                        $this->conflict('receipt_original_too_large');
                    }
                    hash_update($hash, $chunk);
                }
            } finally {
                fclose($stream);
            }
            $digest = hash_final($hash);

            if ($bytes !== (int) $request->content_bytes || ! hash_equals($request->content_sha256, $digest)) {
                $this->conflict('receipt_original_mismatch');
            }
            $record->update(['capture_id' => $request->capture_id, 'capture_integrator_id' => $integrator->id,
                'content_sha256'          => $digest, 'content_bytes' => $bytes]);

            return $record->refresh();
        });

        return new PatientExamResource($record);
    }

    private function conflict(string $code): never
    {
        $exception           = ValidationException::withMessages(['capture_id' => ['O exame remoto não comprova esta aquisição. Confira o original e os vínculos clínicos.']]);
        $exception->response = response()->json(['code' => $code, 'message' => $exception->getMessage(), 'errors' => $exception->errors()], 409);

        throw $exception;
    }
}
