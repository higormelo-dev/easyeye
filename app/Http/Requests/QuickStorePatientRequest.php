<?php

namespace App\Http\Requests;

use App\Support\BrazilianFormat;
use Illuminate\Foundation\Http\FormRequest;

class QuickStorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'cellphone' => ['required', 'string', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'      => 'nome completo',
            'cellphone' => 'celular',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cellphone') && $this->input('cellphone') !== null) {
            $this->merge([
                'cellphone' => BrazilianFormat::canonicalPhone((string) $this->input('cellphone')),
            ]);
        }
    }
}
