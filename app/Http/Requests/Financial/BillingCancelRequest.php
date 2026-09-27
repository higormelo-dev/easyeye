<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelamento de guia ou de lote do faturamento: motivo obrigatório (vai para
 * a própria linha — cancel_reason — e para a trilha de auditoria). Quem pode é
 * decidido na rota (grupo financeiro) e no controller (Gate do financeiro); a
 * clínica é conferida no binding e de novo no service, sob lock.
 */
class BillingCancelRequest extends FormRequest
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:' . self::REASON_MIN, 'max:' . self::REASON_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => __('financial_billing.validation.reason_required'),
            'reason.string'   => __('financial_billing.validation.reason_required'),
            'reason.min'      => __('financial_billing.validation.reason_min', ['min' => self::REASON_MIN]),
            'reason.max'      => __('financial_billing.validation.reason_max', ['max' => self::REASON_MAX]),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) trans('financial_billing.attributes');
    }

    /** Espaços nas pontas não contam para o mínimo (TrimStrings já cobre o HTTP; aqui vale para qualquer chamador). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
