<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->company_cnpj) {
            $this->merge(['company_cnpj' => preg_replace('/\D/', '', $this->company_cnpj)]);
        }
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password'              => ['required', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string'],
            'company_name'          => ['required', 'string', 'max:255'],
            'company_cnpj'          => [
                'nullable',
                'string',
                'max:14',
                Rule::unique('entities', 'national_registration')->whereNotNull('national_registration'),
            ],
            'plan_id' => ['nullable', 'uuid', 'exists:plans,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_cnpj.unique' => __('validation.cnpj_already_registered'),
        ];
    }
}
