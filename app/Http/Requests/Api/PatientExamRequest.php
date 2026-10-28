<?php

namespace App\Http\Requests\Api;

use App\Models\EntityIntegratorEquipment;
use App\Models\{ExamType, Schedule};
use App\Rules\IntegratorExamArchive;
use App\Support\IntegratorClinicalIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class PatientExamRequest extends FormRequest
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
            // Defesa em profundidade: patient_id é sempre derivado do segmento
            // de rota, nunca do body. Rejeita explicitamente em vez de apenas
            // ignorar, para não mascarar um client tentando reatribuir o exame.
            'patient_id'      => ['prohibited'],
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
            'schedule_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
                    // Ignorar valores vazios, null, ou strings com apenas espaços/hífens
                    if ($value === null || $value === '' || (is_string($value) && trim(trim($value), '-') === '')) {
                        return;
                    }

                    if (! is_string($value) && ! is_int($value)) {
                        $fail(__('validation.custom.validation_invalid.not_schedule_identifier'));

                        return;
                    }

                    if ($this->isUuidLike((string) $value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    // Mesma resolução do PatientExamService (Schedule::identifierMatches):
                    // UUID, SDL-N, número puro ou import_code. Identificador que casa com
                    // MAIS DE UM agendamento é recusado — o exame herdaria paciente e
                    // médico de um agendamento arbitrário.
                    $matches = IntegratorClinicalIdentifier::matches(Schedule::class, (string) $entityId, (string) $value, 'SDL', $this->input('schedule_identifier_namespace'));

                    if ($matches->isEmpty()) {
                        $fail(__('validation.custom.validation_invalid.not_schedule_identifier'));
                    } elseif ($matches->count() > 1) {
                        $fail(__('record_codes.ambiguous_identifier.schedule'));
                    }
                },
            ],
            'name'                 => ['required', 'string', 'max:255', 'min:3'],
            'archive'              => ['required', 'file', new IntegratorExamArchive()],
            'laterality'           => ['nullable', 'integer', 'in:0,1,2'],
            'exam_performed_at'    => ['nullable', 'date'],
            'observation'          => ['nullable', 'string', 'max:1000'],
            'equipment_identifier' => [
                'nullable',
                function ($attribute, $value, $fail) use ($integrator) {
                    if ($value === null) {
                        return;
                    }

                    if (! is_string($value) && ! is_int($value)) {
                        $fail(__('validation.custom.validation_invalid.not_equipment_identifier'));

                        return;
                    }

                    if ($this->isUuidLike((string) $value) && ! Str::isUuid($value)) {
                        $fail(trans('validation.uuid', ['attribute' => $attribute]));

                        return;
                    }

                    // EIQ é numerado POR INTEGRADOR: o escopo aqui e no
                    // PatientExamService::equipmentFindByIdOrCode é o mesmo
                    // (integrator_id do token). Código duplicado (corrida antiga)
                    // é ambíguo — recusado em vez de escolher um.
                    $matchCount = EntityIntegratorEquipment::query()
                        ->where('integrator_id', $integrator->id)
                        ->whereIdentifier((string) $value)
                        ->count();

                    if ($matchCount === 0) {
                        $fail(__('validation.custom.validation_invalid.not_equipment_identifier'));
                    } elseif ($matchCount > 1) {
                        $fail(__('record_codes.ambiguous_identifier.equipment'));
                    }
                },
            ],
        ];
    }

    private function isUuidLike(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $value);
    }

    protected function prepareForValidation(): void
    {
        // Limpar campos que vieram com valores inválidos do multipart
        $data = [];

        foreach ($this->all() as $key => $value) {
            if ($key === 'archive') {
                // Não mexer no arquivo
                $data[$key] = $value;

                continue;
            }

            if ($key === 'laterality' && is_numeric($value)) {
                $data[$key] = (int) $value;

                continue;
            }

            if (is_string($value)) {
                // Limpar strings com caracteres inválidos
                $cleanValue = trim($value);
                $cleanValue = trim($cleanValue, '-');
                $cleanValue = trim($cleanValue);

                // Se ficou vazio, definir como null
                $data[$key] = $cleanValue === '' ? null : $cleanValue;
            } elseif (is_null($value)) {
                $data[$key] = null;
            } else {
                $data[$key] = $value;
            }
        }

        // Garantir que campos opcionais vazios sejam removidos do request
        foreach (['schedule_identifier'] as $field) {
            if (! isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                unset($data[$field]);
            }
        }

        $this->replace($data);
    }
}
