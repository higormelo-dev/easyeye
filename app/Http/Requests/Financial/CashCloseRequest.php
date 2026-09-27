<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;

class CashCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // before_or_equal:today — antes dava para fechar dias que ainda não
        // aconteceram (um clique travava o mês inteiro, inclusive o futuro).
        // "today" segue o APP_TIMEZONE da aplicação (fuso da clínica).
        return [
            'period_start' => ['required', 'date', 'before_or_equal:today'],
            'period_end'   => ['required', 'date', 'after_or_equal:period_start', 'before_or_equal:today'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period_start.before_or_equal' => __('financial_cash_closing.validation.period_start_future'),
            'period_end.before_or_equal'   => __('financial_cash_closing.validation.period_end_future'),
            'period_end.after_or_equal'    => __('financial_cash_closing.validation.period_end_before'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) trans('financial_cash_closing.attributes');
    }
}
