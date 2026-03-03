<?php

namespace App\Http\Requests\Api;

use App\Models\{Doctor, ExamType, Schedule};
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
            'doctor_identifier' => [
                'nullable',
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

                    $query = Doctor::query()
                        ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
                        ->where('entity_users.entity_id', $entityId)
                        ->whereNull('doctors.deleted_at')
                        ->whereNull('entity_users.deleted_at')
                        ->when($isUuid, function ($query) use ($value) {
                            $query->where('doctors.id', $value);
                        }, function ($query) use ($value) {
                            $query->whereRaw('LOWER(doctors.code) = LOWER(?)', [$value]);
                        });

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.doctor_identifier'));
                    }
                },
            ],
            'schedule_identifier' => [
                'nullable',
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
            'name'    => 'nullable|string|max:255|min:3',
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
