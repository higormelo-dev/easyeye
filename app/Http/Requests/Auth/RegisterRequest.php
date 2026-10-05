<?php

namespace App\Http\Requests\Auth;

use App\Models\PlanPrice;
use App\Services\Security\TurnstileVerifier;
use App\Support\BrazilianFormat;
use Closure;
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
            // Preserva letras do CNPJ alfanumérico (IN RFB 2.229/2024), igual Entity::setAttribute.
            $this->merge(['company_cnpj' => BrazilianFormat::documentChars((string) $this->company_cnpj)]);
        }

        if ($this->company_phone) {
            $this->merge(['company_phone' => BrazilianFormat::canonicalPhone((string) $this->company_phone)]);
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
            // Ciclo escolhido no site/cadastro; se o plano não oferecer, o
            // trial usa o ciclo padrão do plano.
            'billing_cycle' => ['nullable', 'string', Rule::in(PlanPrice::sellableCycleValues())],
            // trial (padrão) = teste grátis; checkout = contratar já pagando
            // (sem trial — o front segue para /signup-checkout/contract).
            'start_mode' => ['nullable', 'string', Rule::in(['trial', 'checkout'])],
            // Captcha (Cloudflare Turnstile) — só com as chaves configuradas.
            'turnstile_token' => TurnstileVerifier::enabled()
                ? ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(TurnstileVerifier::class)->verify(is_string($value) ? $value : null, $this->ip())) {
                        $fail(__('auth.register.captcha_failed'));
                    }
                }]
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_cnpj.unique'      => __('validation.cnpj_already_registered'),
            'company_phone.regex'      => __('validation.custom.company_phone.invalid'),
            'turnstile_token.required' => __('auth.register.captcha_required'),
        ];
    }
}
