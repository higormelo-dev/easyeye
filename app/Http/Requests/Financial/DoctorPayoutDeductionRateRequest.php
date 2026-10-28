<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\DoctorPayout\DoctorPayoutDeductionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nova vigência de uma taxa de dedução do repasse (E4): tipo, % sobre o
 * recebido bruto e a partir de quando vale (data do recebimento).
 */
class DoctorPayoutDeductionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $percentage = $this->input('percentage');

        if (is_string($percentage)) {
            $percentage = trim(str_replace(['%', ' '], '', $percentage));
            $this->merge(['percentage' => $percentage === '' ? null : str_replace(',', '.', $percentage)]);
        }

        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'kind'       => ['required', 'string', Rule::in(DoctorPayoutDeductionKind::values())],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'kind'       => __('financial_doctor_payouts.validation.deduction_kind'),
            'percentage' => __('financial_doctor_payouts.validation.percentage'),
            'valid_from' => __('financial_doctor_payouts.validation.valid_from'),
            'notes'      => __('financial_doctor_payouts.validation.notes'),
        ];
    }
}
