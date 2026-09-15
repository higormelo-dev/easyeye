<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegratorQueueHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pending_count'       => ['required', 'integer', 'min:0'],
            'failed_count'        => ['required', 'integer', 'min:0'],
            'blocked_count'       => ['required', 'integer', 'min:0'],
            'sent_last_24h_count' => ['required', 'integer', 'min:0'],

            // O cliente já limita isto antes de sincronizar — o teto aqui
            // é defesa em profundidade, não o mecanismo principal de
            // controle de tamanho.
            'problems'                       => ['present', 'array', 'max:50'],
            'problems.*.id'                  => ['required', 'integer'],
            'problems.*.file_name'           => ['required', 'string', 'max:255'],
            'problems.*.status'              => ['required', 'string', Rule::in(['failed', 'blocked'])],
            'problems.*.schedule_identifier' => ['nullable', 'string', 'max:32'],
            'problems.*.patient_identifier'  => ['nullable', 'string', 'max:64'],
            'problems.*.last_error'          => ['nullable', 'string', 'max:500'],
            'problems.*.attempts'            => ['required', 'integer', 'min:0'],
            'problems.*.api_status'          => ['nullable', 'integer'],
            'problems.*.updated_at'          => ['required', 'date'],
        ];
    }
}
