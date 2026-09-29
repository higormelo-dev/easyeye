<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Motivo obrigatório para reabrir um fechamento ou estornar um pagamento de
 * repasse (mesmos limites da reabertura de caixa).
 */
class DoctorPayoutReasonRequest extends FormRequest
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:' . self::REASON_MIN, 'max:' . self::REASON_MAX],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => __('financial_doctor_payouts.validation.reason')];
    }
}
