<?php

namespace App\Http\Requests\Api;

use App\Models\PatientExam;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

trait ValidatesIntegratorCapture
{
    private function captureRules(): array
    {
        $integrator = $this->attributes->get('integrator');

        return [
            'capture_id' => ['bail', 'nullable', 'uuid', function ($attribute, $value, $fail) use ($integrator) {
                if ($this->header('Idempotency-Key') !== $value) {
                    $fail('A chave de idempotência deve identificar esta aquisição.');
                }

                if (PatientExam::where('capture_integrator_id', $integrator->id)->where('capture_id', $value)->exists()
                    || DB::table('integrator_api_receipts')->where('integrator_id', $integrator->id)->where('capture_id', $value)->whereNotNull('status')->exists()) {
                    $fail('Aquisição já registrada: reutilize a chave e o endpoint originais para obter o recibo.');
                }
            }],
            'content_sha256' => ['bail', 'required_with:capture_id', 'nullable', 'regex:/^[a-f0-9]{64}$/', function ($attribute, $value, $fail) {
                $file = $this->file('archive');

                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    $fail('Original inválido para verificar o hash.');

                    return;
                }
                $hash = hash_file('sha256', $file->getRealPath());

                if ($hash === false || ! hash_equals($value, $hash)) {
                    $fail('Hash do original não confere.');
                }
            }],
            'patient_identifier_namespace'  => ['nullable', 'in:uuid,system,import'],
            'schedule_identifier_namespace' => ['nullable', 'in:uuid,system,import'],
        ];
    }
}
