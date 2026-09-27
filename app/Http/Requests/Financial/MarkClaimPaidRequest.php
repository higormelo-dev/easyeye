<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\PaymentMethod;
use App\Models\BillingClaim;
use App\Services\Financial\BillingService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Registrar recebimento" de uma guia. Tudo opcional: sem paid_amount o
 * BillingService usa valor da guia − glosa; sem paid_at, hoje.
 */
class MarkClaimPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var BillingClaim $claim */
        $claim = $this->route('claim');

        return [
            'paid_amount' => [
                'nullable', 'numeric', 'min:0',
                function (string $attribute, mixed $value, Closure $fail) use ($claim): void {
                    if ((float) $value > (float) $claim->amount) {
                        $fail(__('financial_billing.errors.paid_amount_exceeds_claim'));
                    }
                },
            ],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            // Antes aceitava qualquer texto: um valor fora do enum ia para o
            // lançamento de caixa e lia como null (forma de pagamento perdida).
            'payment_method' => [
                'nullable',
                Rule::in(array_map(fn (PaymentMethod $method) => $method->value, BillingService::RECEIPT_PAYMENT_METHODS)),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'paid_at.before_or_equal' => __('financial_billing.errors.paid_at_future'),
            'payment_method.in'       => __('financial_billing.errors.payment_method_invalid'),
        ];
    }
}
