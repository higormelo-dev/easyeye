<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

/** Cancelar, suspender ou retirar o pedido: motivo obrigatório + revisão vista pela tela. */
class SurgeryReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason'            => ['required', 'string', 'min:3', 'max:500'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
