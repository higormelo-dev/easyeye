<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\PaymentMethod;
use App\Services\Financial\BillingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Registrar recebimento do lote": data (não futura — o caixa aberto na data
 * é conferido no service, sob o lock do caixa), forma (as mesmas do
 * recebimento individual) e observação opcional. Base do recebimento das
 * guias selecionadas (BulkClaimReceiptRequest).
 */
class BatchReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paid_at'        => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => [
                'required',
                Rule::in(array_map(fn (PaymentMethod $method) => $method->value, BillingService::RECEIPT_PAYMENT_METHODS)),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'paid_at.required'        => __('financial_billing.validation.paid_at_required'),
            'paid_at.date'            => __('financial_billing.validation.paid_at_required'),
            'paid_at.before_or_equal' => __('financial_billing.errors.paid_at_future'),
            'payment_method.required' => __('financial_billing.errors.payment_method_invalid'),
            'payment_method.in'       => __('financial_billing.errors.payment_method_invalid'),
            'notes.max'               => __('financial_billing.validation.field_max', ['max' => 1000]),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) trans('financial_billing.attributes');
    }

    /** @return array{paid_at: string, payment_method: string, notes: string|null} */
    public function receiptData(): array
    {
        return [
            'paid_at'        => (string) $this->validated('paid_at'),
            'payment_method' => (string) $this->validated('payment_method'),
            'notes'          => $this->validated('notes'),
        ];
    }
}
