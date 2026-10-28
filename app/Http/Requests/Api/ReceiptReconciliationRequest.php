<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capture_id' => ['required', 'uuid', function ($attribute, $value, $fail) {
                if ($this->header('Idempotency-Key') !== $value) {
                    $fail('A chave de idempotência deve identificar esta aquisição.');
                }
            }],
            'content_sha256'       => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'content_bytes'        => ['required', 'integer', 'between:1,10485760'],
            'patient_identifier'   => ['required', 'uuid'],
            'schedule_identifier'  => ['present', 'nullable', 'uuid'],
            'exam_identifier'      => ['required', 'uuid'],
            'equipment_identifier' => ['required', 'uuid'],
            'laterality'           => ['present', 'nullable', 'integer', 'in:0,1,2'],
            'exam_performed_at'    => ['nullable', 'date'],
        ];
    }
}
