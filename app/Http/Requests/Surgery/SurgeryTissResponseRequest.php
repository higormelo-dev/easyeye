<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

/** XML de resposta da operadora anexado pela secretária (quando não veio na resposta do envio). */
class SurgeryTissResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['xml_file' => ['required', 'file', 'extensions:xml', 'max:2048']];
    }
}
