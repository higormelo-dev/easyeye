<?php

namespace App\Http\Requests\Api;

use App\Models\{EntityIntegratorEquipment, ExamType, Patient, PatientExam, Schedule};
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ExamRequest extends FormRequest
{
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
            'exam_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
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

                    if ($this->isUuidLike($value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    $query = Patient::query()
                        ->where('entity_id', $entityId)
                        ->whereNull('deleted_at');

                    [$column, $lookupValue] = match (true) {
                        Str::isUuid($value) => ['id', $value],
                        ctype_digit($value) => ['code', sprintf('PAC-%010d', (int) $value)],
                        default             => ['code', $value],
                    };
                    $query->where($column, $lookupValue);

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.not_patient_identifier'));
                    }
                },
            ],
            'schedule_identifier' => [
                'required_without:patient_identifier',
                function ($attribute, $value, $fail) use ($entityId) {
                    if ($value === null) {
                        return;
                    }

                    if ($this->isUuidLike($value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    $query = Schedule::query()
                        ->where('entity_id', $entityId)
                        ->whereNull('deleted_at');

                    [$column, $lookupValue] = match (true) {
                        Str::isUuid($value) => ['id', $value],
                        ctype_digit($value) => ['code', sprintf('SDL-%010d', (int) $value)],
                        default             => ['code', $value],
                    };
                    $query->where($column, $lookupValue);

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.not_schedule_identifier'));
                    }
                },
            ],
            'laterality'           => ['nullable', 'integer', 'in:0,1,2'],
            'equipment_identifier' => [
                'nullable',
                function ($attribute, $value, $fail) use ($integrator) {
                    if ($value === null) {
                        return;
                    }

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
            'name' => [
                'required',
                'string',
                'max:255',
                'min:3',
                function ($attribute, $value, $fail) use ($entityId) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    // name é único por entidade
                    $exists = PatientExam::query()
                        ->whereHas('patient', function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)->whereNull('deleted_at');
                        })
                        ->whereRaw('LOWER(name) = LOWER(?)', [$value])
                        ->exists();

                    if ($exists) {
                        $fail(__('validation.custom.validation_unique.name_combination'));
                    }
                },
            ],
            // Paridade com PatientExamRequest: equipamentos também exportam .emr.
            // bmp: topógrafos (ex.: MediWorks DEA520) exportam BMP nativo.
            // pdf: campímetros/biômetros/aberrômetros produzem LAUDO PDF — sem
            // pdf aqui, essas modalidades não têm o que enviar (o import manual
            // do Gerenciador de Imagens já aceita pdf desde sempre).
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
