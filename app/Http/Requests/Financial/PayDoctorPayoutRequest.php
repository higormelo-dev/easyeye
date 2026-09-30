<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrar um pagamento (parcial ou o saldo) de um fechamento de repasse.
 * O serviço confere, sob lock, 0 < valor ≤ saldo e que o "já pago" visto na
 * tela (expected_paid_cents) não mudou — duplo envio vira 422.
 */
class PayDoctorPayoutRequest extends FormRequest
{
    /** Formas aceitas para pagar médico (sem cartão/cortesia). */
    public const METHODS = [PaymentMethod::Transfer->value, PaymentMethod::Cash->value];

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
    }

    public function rules(): array
    {
        return [
            'amount'              => ['required', 'numeric', 'min:0', 'max:' . DoctorPayoutRuleRequest::MONEY_MAX, 'decimal:0,2'],
            'expected_paid_cents' => ['required', 'integer', 'min:0'],
            'paid_at'             => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method'      => ['required', 'string', Rule::in(self::METHODS)],
            'payment_notes'       => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'amount'         => __('financial_doctor_payouts.validation.amount'),
            'paid_at'        => __('financial_doctor_payouts.validation.paid_at'),
            'payment_method' => __('financial_doctor_payouts.validation.payment_method'),
            'payment_notes'  => __('financial_doctor_payouts.validation.notes'),
        ];
    }
}
