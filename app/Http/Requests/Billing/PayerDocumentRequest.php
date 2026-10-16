<?php

namespace App\Http\Requests\Billing;

use App\Models\Entity;
use App\Rules\CpfCnpj;
use App\Support\BrazilianFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CPF/CNPJ da clínica para o checkout (PUT payer-document). A autorização é do middleware `billing.contact` (admin,
 * financeiro ou dono da clínica da sessão): a clínica vem dele, nunca do corpo. O documento chega canônico (sem
 * pontuação, maiúsculas, letras do CNPJ alfanumérico preservadas — a mesma regra do RegisterRequest) e é único entre as
 * clínicas (a própria fica fora da conferência).
 */
class PayerDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_scalar($this->input('document'))) {
            $this->merge(['document' => BrazilianFormat::documentChars((string) $this->input('document'))]);
        }
    }

    public function rules(): array
    {
        return [
            'document' => [
                'required',
                'string',
                'max:14',
                new CpfCnpj(),
                Rule::unique('entities', 'national_registration')->ignore($this->entity()->id)->whereNotNull('national_registration'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document.unique' => __('validation.cnpj_already_registered'),
        ];
    }

    public function attributes(): array
    {
        return ['document' => __('auth.register.document')];
    }

    public function document(): string
    {
        return (string) $this->validated()['document'];
    }

    public function entity(): Entity
    {
        return $this->attributes->get('checkout_entity');
    }
}
