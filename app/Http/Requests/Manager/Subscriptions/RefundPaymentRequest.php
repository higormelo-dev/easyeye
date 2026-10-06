<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager\Subscriptions;

use App\Http\Requests\Manager\DestructiveActionRequest;

/**
 * Estorno de pagamento pelo manager: total ou parcial (com o valor) e a
 * justificativa obrigatória (DestructiveActionRequest — vai para a
 * auditoria).
 */
class RefundPaymentRequest extends DestructiveActionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'mode'   => ['required', 'string', 'in:full,partial'],
            'amount' => ['required_if:mode,partial', 'nullable', 'numeric', 'min:0.01', 'max:99999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'amount.required_if' => __('manager_subscriptions.refund.errors.amount_required'),
            'amount.min'         => __('manager_subscriptions.refund.errors.amount_required'),
        ];
    }

    /** Valor do parcial; null = total. */
    public function amount(): ?float
    {
        return $this->validated('mode') === 'partial' ? round((float) $this->validated('amount'), 2) : null;
    }
}
