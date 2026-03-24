<?php

namespace App\Http\Requests\Api;

use App\Models\{ExamType, PatientExam, Schedule};
use App\Models\EntityIntegratorEquipment;
use Illuminate\Foundation\Http\FormRequest;

class PatientExamRequest extends FormRequest
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
            'schedule_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
                    // Ignorar valores vazios, null, ou strings com apenas espaços/hífens
                    if ($value === null || $value === '' || (is_string($value) && trim(trim($value), '-') === '')) {
                        return;
                    }

                    // Verifica se é um UUID válido
                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    $exists = Schedule::query()
                        ->where('entity_id', $entityId)
                        ->whereNull('deleted_at')
                        ->when($isUuid, function ($query) use ($value) {
                            $query->where('id', $value);
                        }, function ($query) use ($value) {
                            $query->whereRaw('LOWER(code) = LOWER(?)', [$value]);
                        })
                        ->exists();

                    if (! $exists) {
                        $fail(__('validation.custom.validation_invalid.schedule_identifier'));
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                'min:3',
                function ($attribute, $value, $fail) use ($entityId) {
                    // Ignorar se name for vazio
                    if ($value === null || $value === '') {
                        return;
                    }

                    // name é único por entidade — mesma lógica da service (busca só por name)
                    $existsQuery = PatientExam::query()
                        ->whereHas('patient', function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)
                                ->whereNull('deleted_at');
                        })
                        ->whereRaw('LOWER(name) = LOWER(?)', [$value]);

                    // Se estiver atualizando, ignorar o registro atual
                    // O parâmetro de rota é {exam} (apiResource e rota POST customizada)
                    $examParam = $this->route('exam');

                    if ($examParam) {
                        $isUuidParam = (bool) preg_match(
                            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                            (string) $examParam
                        );

                        if ($isUuidParam) {
                            $existsQuery->where('id', '!=', $examParam);
                        } else {
                            $existsQuery->where('code', '!=', $examParam);
                        }
                    }

                    if ($existsQuery->exists()) {
                        $fail(__('validation.custom.validation_unique.name_combination'));
                    }
                },
            ],
            'archive'              => 'required|file|mimes:jpg,jpeg,png|max:10240',
            'laterality'           => ['nullable', 'integer', 'in:0,1,2'],
            'equipment_identifier' => [
                'nullable',
                function ($attribute, $value, $fail) use ($integrator) {
                    if ($value === null) {
                        return;
                    }

                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    $query = EntityIntegratorEquipment::query()
                        ->where('integrator_id', $integrator->id)
                        ->whereNull('deleted_at');

                    if ($isUuid) {
                        $query->where('id', $value);
                    } else {
                        $query->where('code', $value);
                    }

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.equipment_identifier'));
                    }
                },
            ],
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
