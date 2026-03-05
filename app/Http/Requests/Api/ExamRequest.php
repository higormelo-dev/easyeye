<?php

namespace App\Http\Requests\Api;

use App\Models\{Doctor, ExamType, Patient, PatientExam, Schedule};
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;

        return [
            'patient_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
                    $query = Patient::query()
                        ->where('entity_id', $entityId)
                        ->whereNull('deleted_at');

                    // Verifica se é um UUID válido
                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    if ($isUuid) {
                        $query->where('id', $value);
                    } else {
                        $query->where('code', $value);
                    }

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.patient_identifier'));
                    }
                },
            ],
            'exam_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
                    $query = ExamType::query()
                        ->where(function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)
                                ->orWhereNull('entity_id');
                        })
                        ->whereNull('deleted_at');

                    // Verifica se é um UUID válido
                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    if ($isUuid) {
                        $query->where('id', $value);
                    } else {
                        $query->where('code', $value);
                    }

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.exam_identifier'));
                    }
                },
            ],
            'doctor_identifier' => [
                'nullable',
                function ($attribute, $value, $fail) use ($entityId) {
                    if (empty($value)) {
                        return;
                    }

                    // Verifica se é um UUID válido
                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    $query = Doctor::query()
                        ->whereHas('entityUser', function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)
                                ->whereNull('deleted_at');
                        })
                        ->whereNull('deleted_at');

                    if ($isUuid) {
                        $query->where('id', $value);
                    } else {
                        $query->whereRaw('LOWER(code) = LOWER(?)', [$value]);
                    }

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.doctor_identifier'));
                    }
                },
            ],
            'schedule_identifier' => [
                'nullable',
                function ($attribute, $value, $fail) use ($entityId) {
                    if (empty($value)) {
                        return;
                    }

                    // Verifica se é um UUID válido
                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    $query = Schedule::query()
                        ->where('entity_id', $entityId)
                        ->whereNull('deleted_at');

                    if ($isUuid) {
                        $query->where('id', $value);
                    } else {
                        $query->whereRaw('LOWER(code) = LOWER(?)', [$value]);
                    }

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.schedule_identifier'));
                    }
                },
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
                'min:3',
                function ($attribute, $value, $fail) use ($entityId) {
                    // Ignorar se name for vazio
                    if ($value === null || $value === '') {
                        return;
                    }

                    // Obter os valores dos identificadores
                    $patientIdentifier  = $this->input('patient_identifier');
                    $examIdentifier     = $this->input('exam_identifier');
                    $doctorIdentifier   = $this->input('doctor_identifier');
                    $scheduleIdentifier = $this->input('schedule_identifier');

                    // Resolver exam_id a partir do patient_identifier
                    $patientId = null;

                    if ($patientIdentifier) {
                        $isUuid    = Str::isUuid($patientIdentifier);
                        $examQuery = Patient::query()
                            ->where(function ($query) use ($entityId) {
                                $query->where('entity_id', $entityId)
                                    ->orWhereNull('entity_id');
                            })
                            ->whereNull('deleted_at');

                        if ($isUuid) {
                            $examQuery->where('id', $patientIdentifier);
                        } else {
                            $examQuery->whereRaw('LOWER(code) = LOWER(?)', [$patientIdentifier]);
                        }

                        $patientId = $examQuery->value('id');
                    }

                    // Resolver exam_id a partir do exam_identifier
                    $examId = null;

                    if ($examIdentifier) {
                        $isUuid    = Str::isUuid($examIdentifier);
                        $examQuery = ExamType::query()
                            ->where(function ($query) use ($entityId) {
                                $query->where('entity_id', $entityId)
                                    ->orWhereNull('entity_id');
                            })
                            ->whereNull('deleted_at');

                        if ($isUuid) {
                            $examQuery->where('id', $examIdentifier);
                        } else {
                            $examQuery->whereRaw('LOWER(code) = LOWER(?)', [$examIdentifier]);
                        }

                        $examId = $examQuery->value('id');
                    }

                    // Resolver doctor_id a partir do doctor_identifier
                    $doctorId = null;

                    if ($doctorIdentifier && trim(trim($doctorIdentifier), '-') !== '') {
                        $isUuid      = Str::isUuid($doctorIdentifier);
                        $doctorQuery = Doctor::query()
                            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
                            ->where('entity_users.entity_id', $entityId)
                            ->whereNull('doctors.deleted_at')
                            ->whereNull('entity_users.deleted_at');

                        if ($isUuid) {
                            $doctorQuery->where('doctors.id', $doctorIdentifier);
                        } else {
                            $doctorQuery->whereRaw('LOWER(doctors.code) = LOWER(?)', [$doctorIdentifier]);
                        }

                        $doctorId = $doctorQuery->value('doctors.id');
                    }

                    // Resolver schedule_id a partir do schedule_identifier
                    $scheduleId = null;

                    if ($scheduleIdentifier && trim(trim($scheduleIdentifier), '-') !== '') {
                        $isUuid        = Str::isUuid($scheduleIdentifier);
                        $scheduleQuery = Schedule::query()
                            ->where('entity_id', $entityId)
                            ->whereNull('deleted_at');

                        if ($isUuid) {
                            $scheduleQuery->where('id', $scheduleIdentifier);
                        } else {
                            $scheduleQuery->whereRaw('LOWER(code) = LOWER(?)', [$scheduleIdentifier]);
                        }

                        $scheduleId = $scheduleQuery->value('id');
                    }

                    // Verificar se já existe um registro com a mesma combinação
                    $existsQuery = PatientExam::query()
                        ->whereHas('patient', function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)
                                ->whereNull('deleted_at');
                        })
                        ->whereRaw('LOWER(name) = LOWER(?)', [$value]);

                    if ($patientId) {
                        $existsQuery->where('patient_id', $patientId);
                    }

                    if ($examId) {
                        $existsQuery->where('exam_id', $examId);
                    } else {
                        $existsQuery->whereNull('exam_id');
                    }

                    if ($doctorId) {
                        $existsQuery->where('doctor_id', $doctorId);
                    } else {
                        $existsQuery->whereNull('doctor_id');
                    }

                    if ($scheduleId) {
                        $existsQuery->where('schedule_id', $scheduleId);
                    } else {
                        $existsQuery->whereNull('schedule_id');
                    }

                    // Se estiver atualizando, ignorar o registro atual
                    $patientExamId = $this->route('idOrCode');

                    if ($patientExamId) {
                        $existsQuery->where('id', '!=', $patientExamId);
                    }

                    if ($existsQuery->exists()) {
                        $fail(__('validation.custom.validation_unique.name_combination'));
                    }
                },
            ],
            'archive' => 'required|file|mimes:jpg,jpeg,png|max:10240',
        ];
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
        foreach (['doctor_identifier', 'schedule_identifier'] as $field) {
            if (! isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                unset($data[$field]);
            }
        }

        $this->replace($data);
    }
}
