<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reabertura de período de caixa: motivo obrigatório (vai para a própria
 * linha do CashClose e para a trilha de auditoria). Quem pode reabrir é
 * decidido na rota (entity.role:admin); a clínica é conferida no binding e no
 * controller.
 */
class ReopenCashCloseRequest extends FormRequest
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
            'reason.required' => __('financial_cash_closing.validation.reason_required'),
            'reason.string'   => __('financial_cash_closing.validation.reason_required'),
            'reason.min'      => __('financial_cash_closing.validation.reason_min', ['min' => self::REASON_MIN]),
            'reason.max'      => __('financial_cash_closing.validation.reason_max', ['max' => self::REASON_MAX]),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) trans('financial_cash_closing.attributes');
    }

    /** Espaços nas pontas não contam para o mínimo (TrimStrings já cobre o HTTP; aqui vale para qualquer chamador). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
