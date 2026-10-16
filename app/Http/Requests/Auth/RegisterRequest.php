<?php

namespace App\Http\Requests\Auth;

use App\Models\PlanPrice;
use App\Rules\CpfCnpj;
use App\Services\Security\TurnstileVerifier;
use App\Services\TermsService;
use App\Support\BrazilianFormat;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\{Rule, Validator};
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
            // CPF (11) ou CNPJ (14, inclusive alfanumérico) do responsável pela cobrança, em entities.national_registration.
            // Obrigatório em "Contratar agora" (start_mode=checkout): o Asaas recusa a assinatura de cliente sem
            // documento. No teste grátis segue opcional, mas, se vier, tem de ser válido.
            'company_cnpj' => [
                'required_if:start_mode,checkout',
                'nullable',
                'string',
                'max:14',
                new CpfCnpj(),
                Rule::unique('entities', 'national_registration')->whereNotNull('national_registration'),
            ],
            'plan_id' => ['nullable', 'uuid', 'exists:plans,id'],
            // Ciclo escolhido no site/cadastro; se o plano não oferecer, o
            // trial usa o ciclo padrão do plano.
            'billing_cycle' => ['nullable', 'string', Rule::in(PlanPrice::sellableCycleValues())],
            // trial (padrão) = teste grátis; checkout = contratar já pagando
            // (sem trial — o front segue para /signup-checkout/contract).
            'start_mode' => ['nullable', 'string', Rule::in(['trial', 'checkout'])],
            // Aceite dos Termos de Uso e da Política de Privacidade: a obrigatoriedade depende de haver versão
            // VIGENTE (resolvida no servidor) e é conferida em after() — a regra `accepted` é implícita e falharia
            // também quando não há nada publicado.
            'terms_accepted' => ['nullable', 'boolean'],
            // Versões que a tela exibiu e a pessoa aceitou (as de `terms.documents[].version_id` do GET /register).
            'terms_version_ids'   => ['nullable', 'array', 'max:10'],
            'terms_version_ids.*' => ['string', 'uuid'],
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

    /**
     * Com versão vigente dos termos, o aceite é obrigatório (422 em terms_accepted) e vale para EXATAMENTE as versões
     * que a tela mostrou: `terms_version_ids` tem de ser o mesmo conjunto das versões vigentes agora — se uma versão
     * foi publicada enquanto a pessoa preenchia o cadastro, o aceite seria de um texto que ela não leu (422
     * terms_changed, nada gravado). Documento obrigatório SEM versão vigente não bloqueia — falha aberta DELIBERADA
     * (o cadastro não pode parar por falta de publicação), mas com um aviso no log POR TIPO ausente: é o sinal de
     * que aquele aceite deixou de ser exigido e gravado.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $current = app(TermsService::class)->currentRequired();

            if ($validator->errors()->isEmpty()) {
                foreach (array_diff(TermsService::REQUIRED_TYPES, array_keys($current)) as $type) {
                    Log::warning('terms.required_version_missing', ['type' => $type]);
                }
            }

            if ($current === [] || $validator->errors()->has('terms_version_ids') || $validator->errors()->has('terms_version_ids.*')) {
                return;
            }

            if (! $this->boolean('terms_accepted')) {
                $validator->errors()->add('terms_accepted', __('auth.register.terms_required'));

                return;
            }

            if (! TermsService::sameVersionSet($this->input('terms_version_ids'), $current)) {
                $validator->errors()->add('terms_accepted', __('auth.register.terms_changed'));
            }
        }];
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
