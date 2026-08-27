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

        if ($this->company_phone) {
            $this->merge(['company_phone' => preg_replace('/\D/', '', $this->company_phone)]);
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
            // WhatsApp do responsável: canal de contato do time comercial.
            // O campo já existia no wizard mas era descartado — agora é
            // obrigatório, verificado por código OTP após o registro.
            // 10-11 dígitos = DDD + fixo/celular BR (DDI 55 é normalizado
            // pelo WhatsAppService no envio).
            'company_phone' => ['required', 'string', 'regex:/^\d{10,11}$/'],
            'company_cnpj'  => [
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
            'company_phone.regex' => __('validation.custom.company_phone.invalid'),
        ];
    }
}
