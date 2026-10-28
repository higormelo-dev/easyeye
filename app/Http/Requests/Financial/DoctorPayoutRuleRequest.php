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
 *
 * Nova vigência (só ao editar, opcional): `effective_from` encerra a regra
 * atual na véspera e cria outra com os dados do formulário a partir dessa
 * data — depois do início da atual e até o fim dela (se tiver).
 *
 * Divisão (E4, opcional, só regra percentual): `percentage` = parte do GRUPO
 * sobre o recebido líquido (a clínica fica com o restante); `participants` =
 * executor (médico do item) e/ou médicos fixos da clínica, cada um com % do
 * grupo, somando exatamente 100%, no máximo um executor, sem médico repetido.
 */
class DoctorPayoutRuleRequest extends FormRequest
{
    public const ALL_TYPES = 'all';

    public const MONEY_MAX = '9999999999.99';

    public const MAX_PARTICIPANTS = 10;

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

        if (is_array($this->input('participants'))) {
            $normalized['participants'] = array_values(array_map(fn ($participant) => is_array($participant) ? [
                ...$participant,
                'percentage' => self::normalizeDecimal($participant['percentage'] ?? null),
                'doctor_id'  => is_string($participant['doctor_id'] ?? null) && trim($participant['doctor_id']) !== '' ? $participant['doctor_id'] : null,
            ] : $participant, $this->input('participants')));
        }

        foreach (['doctor_id', 'visit_type_id', 'procedure_id', 'exam_type_id', 'covenant_id', 'valid_from', 'valid_until', 'effective_from', 'notes'] as $field) {
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
            // Nova vigência só existe ao editar uma regra; escolhida, a data é
            // obrigatória (sem ela o envio viraria correção retroativa).
            'change_mode'    => $this->isMethod('post') ? ['prohibited'] : ['nullable', 'string', Rule::in(['new'])],
            'effective_from' => $this->isMethod('post') ? ['prohibited'] : ['nullable', 'required_if:change_mode,new', 'date_format:Y-m-d'],

            'participants'             => ['nullable', 'array', 'max:' . self::MAX_PARTICIPANTS],
            'participants.*.role'      => ['required', 'string', Rule::in(['executor', 'doctor'])],
            'participants.*.doctor_id' => [
                'nullable', 'uuid', 'required_if:participants.*.role,doctor',
                Rule::exists('doctors', 'id')->where(fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->whereIn('entity_user_id', DB::table('entity_users')->select('id')->where('entity_id', $entityId))),
            ],
            'participants.*.percentage' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
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

                $this->validateEffectiveFrom($validator);
                $this->validateParticipants($validator);
            },
        ];
    }

    /**
     * Nova vigência: depois do início da regra atual (ela fica com pelo menos
     * um dia), até o fim dela, e antes do fim informado para a nova.
     */
    private function validateEffectiveFrom(Validator $validator): void
    {
        $from = $this->input('effective_from');
        $rule = $this->route('rule');

        if (! is_string($from) || ! is_object($rule)) {
            return;
        }

        $current = [
            'from'  => $rule->valid_from?->toDateString(),
            'until' => $rule->valid_until?->toDateString(),
        ];
        $until = $this->input('valid_until');

        if (($current['from'] !== null && $from <= $current['from'])
            || ($current['until'] !== null && $from > $current['until'])
            || (is_string($until) && $until < $from)) {
            $validator->errors()->add('effective_from', __('financial_doctor_payouts.errors.effective_from_range'));
        }
    }

    /** Divisão coerente: só em regra percentual, soma 100%, um executor, médico sem repetir. */
    private function validateParticipants(Validator $validator): void
    {
        $participants = (array) ($this->input('participants') ?? []);

        if ($participants === []) {
            return;
        }

        if ($this->input('calculation') !== DoctorPayoutCalculation::Percentage->value) {
            $validator->errors()->add('participants', __('financial_doctor_payouts.errors.participants_percentage_only'));

            return;
        }

        $sum       = array_sum(array_map(fn (array $participant) => (int) round(((float) $participant['percentage']) * 100), $participants));
        $roles     = array_count_values(array_column($participants, 'role'));
        $doctorIds = array_filter(array_column($participants, 'doctor_id'));

        if ($sum !== 10000) {
            $validator->errors()->add('participants', __('financial_doctor_payouts.errors.participants_sum'));
        }

        if (($roles['executor'] ?? 0) > 1) {
            $validator->errors()->add('participants', __('financial_doctor_payouts.errors.participants_executor'));
        }

        if (count($doctorIds) !== count(array_unique($doctorIds))) {
            $validator->errors()->add('participants', __('financial_doctor_payouts.errors.participants_duplicate'));
        }

        foreach ($participants as $index => $participant) {
            if ($participant['role'] === 'executor' && $participant['doctor_id'] !== null) {
                $validator->errors()->add("participants.{$index}.doctor_id", __('financial_doctor_payouts.errors.participants_executor_doctor'));
            }
        }
    }

    public function attributes(): array
    {
        return [
            'doctor_id'      => __('financial_doctor_payouts.validation.doctor'),
            'service_type'   => __('financial_doctor_payouts.validation.service_type'),
            'visit_type_id'  => __('financial_doctor_payouts.validation.visit_type'),
            'procedure_id'   => __('financial_doctor_payouts.validation.procedure'),
            'exam_type_id'   => __('financial_doctor_payouts.validation.exam_type'),
            'payer_scope'    => __('financial_doctor_payouts.validation.payer_scope'),
            'covenant_id'    => __('financial_doctor_payouts.validation.covenant'),
            'calculation'    => __('financial_doctor_payouts.validation.calculation'),
            'percentage'     => __('financial_doctor_payouts.validation.percentage'),
            'fixed_amount'   => __('financial_doctor_payouts.validation.fixed_amount'),
            'valid_from'     => __('financial_doctor_payouts.validation.valid_from'),
            'valid_until'    => __('financial_doctor_payouts.validation.valid_until'),
            'notes'          => __('financial_doctor_payouts.validation.notes'),
            'effective_from' => __('financial_doctor_payouts.validation.effective_from'),
        ];
    }

    public function messages(): array
    {
        return [
            'effective_from.required_if' => __('financial_doctor_payouts.errors.effective_from_required'),
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
