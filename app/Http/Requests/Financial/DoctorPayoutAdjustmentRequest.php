<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajuste manual de um fechamento: descrição + tipo (acréscimo/desconto) +
 * valor positivo. O sinal vem do tipo (signedCents()).
 */
class DoctorPayoutAdjustmentRequest extends FormRequest
{
    public const KINDS = ['credit', 'debit'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');

        if (is_string($amount)) {
            $amount = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $amount));

            if (str_contains($amount, ',')) {
                $amount = str_replace(',', '.', str_replace('.', '', $amount));
            }

            $this->merge(['amount' => $amount === '' ? null : $amount]);
        }

        if (is_string($this->input('description'))) {
            $this->merge(['description' => trim($this->input('description'))]);
        }
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'kind'        => ['required', 'string', Rule::in(self::KINDS)],
            'amount'      => ['required', 'numeric', 'gt:0', 'max:' . DoctorPayoutRuleRequest::MONEY_MAX, 'decimal:0,2'],
        ];
    }

    /** Centavos com sinal: acréscimo positivo, desconto negativo. */
    public function signedCents(): int
    {
        $cents = Money::toCents($this->validated('amount'));

        return $this->validated('kind') === 'debit' ? -$cents : $cents;
    }

    public function attributes(): array
    {
        return [
            'description' => __('financial_doctor_payouts.validation.description'),
            'amount'      => __('financial_doctor_payouts.validation.amount'),
        ];
    }
}
