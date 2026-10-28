<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Validator;

/**
 * Valida o detalhamento de pagamento misto do caixa, espelhando a regra do
 * CashierBook do smart_oftal: nas formas combinadas (crédito+dinheiro,
 * débito+dinheiro) a soma das parcelas exibidas deve igualar o valor total,
 * e formas com crédito exigem ao menos uma parcela.
 *
 * O FormRequest que usa este trait deve invocar validatePaymentBreakdown()
 * dentro de withValidator(), e ter os campos amount/amount_cash/amount_credit/
 * amount_debit/installments/payment_method.
 */
trait ValidatesPaymentBreakdown
{
    protected function validatePaymentBreakdown(Validator $validator): void
    {
        // Forma inválida já é acusada pelo Rule::enum; não duplica o erro aqui.
        $method = PaymentMethod::tryFrom((string) $this->input('payment_method'));

        if ($method === null) {
            return;
        }

        if ($method->isCombined()) {
            $this->assertBreakdownMatchesTotal($validator, $method);
        }

        if ($method->usesInstallments() && (int) $this->input('installments') < 1) {
            $validator->errors()->add('installments', __('schedules.cash_installments_required'));
        }
    }

    private function assertBreakdownMatchesTotal(Validator $validator, PaymentMethod $method): void
    {
        $fields = array_filter([
            $method->showsCredit() ? 'amount_credit' : null,
            $method->showsDebit() ? 'amount_debit' : null,
            $method->showsCash() ? 'amount_cash' : null,
        ]);

        $sum     = 0.0;
        $missing = false;

        foreach ($fields as $field) {
            $value = $this->input($field);

            if ($value === null || $value === '') {
                $validator->errors()->add($field, __('validation.required', [
                    'attribute' => __("schedules.cash_{$field}"),
                ]));
                $missing = true;

                continue;
            }

            $sum += (float) $value;
        }

        if (! $missing && round($sum, 2) !== round((float) $this->input('amount'), 2)) {
            $validator->errors()->add('amount', __('schedules.cash_breakdown_mismatch'));
        }
    }
}
