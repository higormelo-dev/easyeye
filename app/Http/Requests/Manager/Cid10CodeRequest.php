<?php

namespace App\Http\Requests\Manager;

use App\Models\Cid10Code;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Código do catálogo global CID-10 (Manager → CID-10): criar ou editar
 * código, descrição e categoria. As travas que dependem do registro (código
 * já usado, justificativa ao mudar o texto de um código oficial) ficam em
 * Cid10CodesController::update.
 */
class Cid10CodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $category = trim((string) $this->input('category', ''));

        $this->merge([
            // "h25.1 " → "H25.1": o formato é validado já normalizado.
            'code'        => mb_strtoupper(trim((string) $this->input('code', ''))),
            'description' => trim((string) $this->input('description', '')),
            'category'    => $category === '' ? null : $category,
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:10', 'regex:' . Cid10Code::CODE_PATTERN,
                Rule::unique('cid10_codes', 'code')->ignore($this->route('cid10')),
            ],
            'description' => ['required', 'string', 'max:1000'],
            'category'    => ['nullable', 'string', 'max:255'],
            'reason'      => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex'  => __('manager_cid10.code_format'),
            'code.unique' => __('manager_cid10.code_taken'),
        ];
    }

    public function attributes(): array
    {
        return [
            'code'        => __('manager_cid10.field_code'),
            'description' => __('manager_cid10.field_description'),
            'category'    => __('manager_cid10.field_category'),
            'reason'      => __('manager_cid10.field_reason'),
        ];
    }
}
