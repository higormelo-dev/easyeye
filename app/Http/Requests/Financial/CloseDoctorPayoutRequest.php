<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, Validator};

/**
 * Fechar o repasse de um médico num período. `expected_*` é a prévia que o
 * usuário conferiu (quantidade de itens e repasse em centavos): o service
 * recalcula dentro do lock e recusa se mudou.
 *
 * O médico pode ter saído da clínica (médico/vínculo excluído): o último
 * repasse dele ainda precisa ser fechado — por isso sem filtro de deleted_at.
 */
class CloseDoctorPayoutRequest extends FormRequest
{
    public const MAX_PERIOD_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        return [
            'doctor_id' => [
                'required', 'uuid',
                Rule::exists('doctors', 'id')->where(fn ($q) => $q
                    ->whereIn('entity_user_id', DB::table('entity_users')->select('id')->where('entity_id', $entityId))),
            ],
            'period_start'   => ['required', 'date_format:Y-m-d'],
            'period_end'     => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:today'],
            'expected_count' => ['required', 'integer', 'min:0'],
            // Base pode ser negativa (estorno de recebimento maior que o novo
            // recebido) com repasse ≥ 0 — o que importa é bater com a prévia.
            'expected_charged_cents' => ['required', 'integer'],
            'expected_payout_cents'  => ['required', 'integer', 'min:0'],
            'notes'                  => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = CarbonImmutable::parse($this->input('period_start'))
                    ->diffInDays(CarbonImmutable::parse($this->input('period_end'))) + 1;

                if ($days > self::MAX_PERIOD_DAYS) {
                    $validator->errors()->add('period_start', __('financial_doctor_payouts.errors.period_too_long', ['days' => self::MAX_PERIOD_DAYS]));
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'period_end.before_or_equal' => __('financial_doctor_payouts.errors.end_in_future'),
            'doctor_id.required'         => __('financial_doctor_payouts.errors.doctor_required'),
        ];
    }

    public function attributes(): array
    {
        return [
            'doctor_id'              => __('financial_doctor_payouts.validation.doctor'),
            'period_start'           => __('financial_doctor_payouts.validation.period_start'),
            'period_end'             => __('financial_doctor_payouts.validation.period_end'),
            'notes'                  => __('financial_doctor_payouts.validation.notes'),
            'expected_count'         => __('financial_doctor_payouts.validation.expected_count'),
            'expected_charged_cents' => __('financial_doctor_payouts.validation.expected_charged'),
            'expected_payout_cents'  => __('financial_doctor_payouts.validation.expected_payout'),
        ];
    }
}
