<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegratorCommandAckRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(['completed', 'failed'])],
            // Diagnóstico devolvido, ou motivo da falha — formato livre por
            // tipo de comando, controller nunca interpreta o conteúdo.
            'result' => ['nullable', 'array'],
        ];
    }
}
