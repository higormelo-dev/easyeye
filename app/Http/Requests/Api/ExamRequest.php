<?php

namespace App\Http\Requests\Api;

use App\Models\{EntityIntegratorEquipment, ExamType, Patient, Schedule};
use App\Support\IntegratorClinicalIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ExamRequest extends FormRequest
{
    use ValidatesIntegratorCapture;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;

        return [
            ...$this->captureRules(),
            'exam_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
                    if (! is_string($value) && ! is_int($value)) {
                        $fail('Identificador clínico deve ser um texto ou número.');

                        return;
                    }
                    $value = (string) $value;

                    if ($this->isUuidLike($value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    $query = ExamType::query()
                        ->where(function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)
                                ->orWhereNull('entity_id');
                        })
                        ->whereNull('deleted_at');

                    [$column, $lookupValue] = match (true) {
                        Str::isUuid($value) => ['id', $value],
                        ctype_digit($value) => ['code', sprintf('ETP-%010d', (int) $value)],
                        default             => ['code', $value],
                    };
                    $query->where($column, $lookupValue);

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.not_exam_identifier'));
                    }
                },
            ],
            'patient_identifier' => [
                'required_without:schedule_identifier',
                function ($attribute, $value, $fail) use ($entityId) {
                    if ($value === null) {
                        return;
                    }

                    if (! is_string($value) && ! is_int($value)) {
                        $fail('Identificador clínico deve ser um texto ou número.');

                        return;
                    }
                    $value = (string) $value;

                    if ($this->isUuidLike($value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    $matches = IntegratorClinicalIdentifier::matches(Patient::class, (string) $entityId, $value, 'PAC', $this->input('patient_identifier_namespace'));

                    if ($matches->isEmpty()) {
                        $fail(__('validation.custom.validation_invalid.not_patient_identifier'));
                    } elseif ($matches->count() > 1) {
                        $fail(__('record_codes.ambiguous_identifier.patient'));
                    }
                },
            ],
            'schedule_identifier' => [
                'required_without:patient_identifier',
                function ($attribute, $value, $fail) use ($entityId) {
                    if ($value === null) {
                        return;
                    }

                    if (! is_string($value) && ! is_int($value)) {
                        $fail('Identificador clínico deve ser um texto ou número.');

                        return;
                    }
                    $value = (string) $value;

                    if ($this->isUuidLike($value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    $matches = IntegratorClinicalIdentifier::matches(Schedule::class, (string) $entityId, $value, 'SDL', $this->input('schedule_identifier_namespace'));

                    if ($matches->isEmpty()) {
                        $fail(__('validation.custom.validation_invalid.not_schedule_identifier'));
                    } elseif ($matches->count() > 1) {
                        $fail(__('record_codes.ambiguous_identifier.schedule'));
                    }
                },
            ],
            'laterality' => ['nullable', 'integer', 'in:0,1,2'],
            // Data/hora real da captura, lida pelo integrador do arquivo do
            // equipamento (ex.: "Exam Date"/"Exam Time" do .EMR). ISO-8601 com
            // offset, ou só a data. Limites contra relógio do PC do aparelho
            // desregulado: 1 dia de folga no futuro, nada antes de 2000.
            'exam_performed_at' => [
                'nullable',
                'date',
                'after_or_equal:2000-01-01',
                'before_or_equal:' . now()->addDay()->toIso8601String(),
            ],
            // Descrição do exame vinda do equipamento (ex.: "Display: Topo 4-Maps").
            'observation'          => ['nullable', 'string', 'max:1000'],
            'equipment_identifier' => [
                'nullable',
                function ($attribute, $value, $fail) use ($integrator) {
                    if ($value === null) {
                        return;
                    }

                    if (! is_string($value) && ! is_int($value)) {
                        $fail('Identificador clínico deve ser um texto ou número.');

                        return;
                    }
                    $value = (string) $value;

                    if ($this->isUuidLike($value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    $query = EntityIntegratorEquipment::query()
                        ->where('integrator_id', $integrator->id)
                        ->whereNull('deleted_at');

                    [$column, $lookupValue] = match (true) {
                        Str::isUuid($value) => ['id', $value],
                        ctype_digit($value) => ['code', sprintf('EIQ-%010d', (int) $value)],
                        default             => ['code', $value],
                    };
                    $query->where($column, $lookupValue);

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.not_equipment_identifier'));
                    }
                },
            ],
            'name'    => ['required', 'string', 'max:255', 'min:3'],
            'archive' => 'required|file|mimes:jpg,jpeg,png,bmp,pdf,emr|max:10240',
        ];
    }

    private function isUuidLike(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $value);
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach ($this->all() as $key => $value) {
            if ($key === 'archive') {
                $data[$key] = $value;

                continue;
            }

            if ($key === 'laterality' && is_numeric($value)) {
                $data[$key] = (int) $value;

                continue;
            }

            if (is_string($value)) {
                $cleanValue = trim($value);
                $cleanValue = trim($cleanValue, '-');
                $cleanValue = trim($cleanValue);

                $data[$key] = $cleanValue === '' ? null : $cleanValue;
            } elseif (is_null($value)) {
                $data[$key] = null;
            } else {
                $data[$key] = $value;
            }
        }

        $this->replace($data);
    }
}
