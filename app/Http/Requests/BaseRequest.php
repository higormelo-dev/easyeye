<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Retorna erros de validação como JSON 422 quando a requisição espera JSON.
     * Comportamento padrão (redirect) é mantido para requests tradicionais.
     */
    protected function failedValidation(Validator $validator): never
    {
        if ($this->expectsJson() || $this->wantsJson()) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Os dados informados são inválidos.',
                    'errors'  => $validator->errors(),
                ], 422),
            );
        }

        parent::failedValidation($validator);
    }
}
