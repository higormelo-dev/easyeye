<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

/** Anulação de implante: motivo obrigatório e o destino da unidade (volta ao estoque ou perda). */
class OperationVoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason'          => ['required', 'string', 'min:3', 'max:500'],
            'return_to_stock' => ['nullable', 'boolean'],
        ];
    }
}
