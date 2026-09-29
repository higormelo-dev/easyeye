<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\DoctorPayout\{DoctorPayoutCalculation, DoctorPayoutPayerScope, DoctorPayoutServiceType};
use App\Services\Financial\DoctorPayouts\DoctorPayoutOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, Validator};
use Illuminate\Validation\Rules\Exists;

/**
 * Regra de repasse (criar/editar). A autorização (Gate ViewFinancial) é do
 * controller; aqui só entra dado da clínica da sessão ou de catálogo global.
 *
 * Coerência (after): no máximo um item específico, compatível com o tipo de
 * serviço; convênio específico só com pagador "convênio"; "todos os tipos"
 * (só ao criar) não combina com item específico.
 */
class DoctorPayoutRuleRequest extends FormRequest
{
    public const ALL_TYPES = 'all';

    public const MONEY_MAX = '9999999999.99';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'percentage'   => self::normalizeDecimal($this->input('percentage')),
            'fixed_amount' => self::normalizeDecimal($this->input('fixed_amount')),
            'active'       => $this->has('active')
                ? filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : true,
        ];

        foreach (['doctor_id', 'visit_type_id', 'procedure_id', 'exam_type_id', 'covenant_id', 'valid_from', 'valid_until', 'notes'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && trim($value) === '') {
                $normalized[$field] = null;
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $entityId     = (string) session('selected_entity_id');
        $serviceTypes = DoctorPayoutServiceType::values();

        if ($this->isMethod('post')) {
            $serviceTypes[] = self::ALL_TYPES;
        }

        return [
            'doctor_id' => [
                'nullable', 'uuid',
                Rule::exists('doctors', 'id')->where(fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->whereIn('entity_user_id', DB::table('entity_users')->select('id')->where('entity_id', $entityId))),
            ],
            'service_type'  => ['required', 'string', Rule::in($serviceTypes)],
            'visit_type_id' => ['nullable', 'uuid', $this->catalogExists('visit_types', $entityId)],
            'procedure_id'  => ['nullable', 'uuid', $this->catalogExists('procedures', $entityId)],
            'exam_type_id'  => ['nullable', 'uuid', $this->catalogExists('exam_types', $entityId)],
            'payer_scope'   => ['required', 'string', Rule::in(DoctorPayoutPayerScope::values())],
            'covenant_id'   => ['nullable', 'uuid', $this->catalogExists('covenants', $entityId)],
            'calculation'   => ['required', 'string', Rule::in(DoctorPayoutCalculation::values())],
            'percentage'    => ['nullable', 'required_if:calculation,percentage', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'fixed_amount'  => ['nullable', 'required_if:calculation,fixed', 'numeric', 'min:0', 'max:' . self::MONEY_MAX, 'decimal:0,2'],
            'valid_from'    => ['nullable', 'date_format:Y-m-d'],
            'valid_until'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'active'        => ['boolean'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $type  = (string) $this->input('service_type');
                $items = array_filter([
                    'visit_type_id' => $this->input('visit_type_id'),
                    'procedure_id'  => $this->input('procedure_id'),
                    'exam_type_id'  => $this->input('exam_type_id'),
                ]);

                if ($type === self::ALL_TYPES && $items !== []) {
                    $validator->errors()->add('service_type', __('financial_doctor_payouts.errors.all_types_with_item'));

                    return;
                }

                if (count($items) > 1
                    || (isset($items['procedure_id']) && $type !== DoctorPayoutServiceType::Procedure->value)
                    || (isset($items['exam_type_id']) && $type !== DoctorPayoutServiceType::Exam->value)
                    || (isset($items['visit_type_id']) && $this->visitTypeServiceType($items['visit_type_id']) !== $type)) {
                    $validator->errors()->add(array_key_first($items), __('financial_doctor_payouts.errors.item_type_mismatch'));
                }

                if ($this->input('covenant_id') !== null && $this->input('payer_scope') !== DoctorPayoutPayerScope::Covenant->value) {
                    $validator->errors()->add('covenant_id', __('financial_doctor_payouts.errors.covenant_scope'));
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'doctor_id'     => __('financial_doctor_payouts.validation.doctor'),
            'service_type'  => __('financial_doctor_payouts.validation.service_type'),
            'visit_type_id' => __('financial_doctor_payouts.validation.visit_type'),
            'procedure_id'  => __('financial_doctor_payouts.validation.procedure'),
            'exam_type_id'  => __('financial_doctor_payouts.validation.exam_type'),
            'payer_scope'   => __('financial_doctor_payouts.validation.payer_scope'),
            'covenant_id'   => __('financial_doctor_payouts.validation.covenant'),
            'calculation'   => __('financial_doctor_payouts.validation.calculation'),
            'percentage'    => __('financial_doctor_payouts.validation.percentage'),
            'fixed_amount'  => __('financial_doctor_payouts.validation.fixed_amount'),
            'valid_from'    => __('financial_doctor_payouts.validation.valid_from'),
            'valid_until'   => __('financial_doctor_payouts.validation.valid_until'),
            'notes'         => __('financial_doctor_payouts.validation.notes'),
        ];
    }

    /** Catálogo da clínica ou global, não excluído. */
    private function catalogExists(string $table, string $entityId): Exists
    {
        return Rule::exists($table, 'id')->where(fn ($q) => $q
            ->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('entity_id', $entityId)->orWhereNull('entity_id')));
    }

    /** Tipo de serviço que o tipo de atendimento gera (pelo tratamento do procedimento dele). */
    private function visitTypeServiceType(string $visitTypeId): string
    {
        $treatment = DB::table('visit_types as vt')
            ->leftJoin('procedures as p', 'p.id', '=', 'vt.procedure_id')
            ->where('vt.id', $visitTypeId)
            ->value('p.treatment');

        return DoctorPayoutOptions::serviceTypeForTreatment($treatment);
    }

    /** "1.234,56" / "60,5" / "R$ 80" → formato numérico com ponto. */
    private static function normalizeDecimal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim(str_replace(['R$', '%', ' ', "\u{00A0}"], '', $value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        }

        return $value;
    }
}
