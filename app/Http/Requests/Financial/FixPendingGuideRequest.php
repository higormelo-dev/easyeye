<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Corrigir pendência" da guia TISS: CID (indicação clínica), carteirinha e
 * nº da autorização. Campo vazio limpa o dado na guia; campo ausente não muda.
 * O formato do CID é o da ANS (letra + 2 dígitos + sufixo opcional) — a
 * pré-validação refeita no service mostra o que ainda faltar. Limites = colunas
 * de tiss_guides (64).
 */
class FixPendingGuideRequest extends FormRequest
{
    /** Mesmo padrão de CidIndicationRequired (pré-validação TISS). */
    public const CID_PATTERN = '/^[A-Z]\d{2}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clinical_indication'     => ['sometimes', 'nullable', 'string', 'max:16', 'regex:' . self::CID_PATTERN],
            'beneficiary_card_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'authorization_number'    => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'clinical_indication.regex'   => __('financial_billing.validation.cid_format'),
            'clinical_indication.max'     => __('financial_billing.validation.cid_format'),
            'clinical_indication.string'  => __('financial_billing.validation.cid_format'),
            'beneficiary_card_number.max' => __('financial_billing.validation.field_max', ['max' => 64]),
            'authorization_number.max'    => __('financial_billing.validation.field_max', ['max' => 64]),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) trans('financial_billing.attributes');
    }

    /** CID e autorização em maiúsculas; espaços nas pontas fora; vazio vira null. */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['clinical_indication', 'beneficiary_card_number', 'authorization_number'] as $field) {
            if (! $this->has($field) || ! is_string($this->input($field))) {
                continue;
            }

            $value = trim($this->input($field));

            if ($field !== 'beneficiary_card_number') {
                $value = mb_strtoupper($value);
            }

            $merge[$field] = $value === '' ? null : $value;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
