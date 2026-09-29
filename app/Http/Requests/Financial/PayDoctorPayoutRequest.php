<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrar o pagamento de um fechamento de repasse. O valor é sempre o total
 * líquido do fechamento (itens + ajustes) — não vem do cliente.
 */
class PayDoctorPayoutRequest extends FormRequest
{
    /** Formas aceitas para pagar médico (sem cartão/cortesia). */
    public const METHODS = [PaymentMethod::Transfer->value, PaymentMethod::Cash->value];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paid_at'        => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['required', 'string', Rule::in(self::METHODS)],
            'payment_notes'  => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'paid_at'        => __('financial_doctor_payouts.validation.paid_at'),
            'payment_method' => __('financial_doctor_payouts.validation.payment_method'),
            'payment_notes'  => __('financial_doctor_payouts.validation.notes'),
        ];
    }
}
