<?php

namespace App\Http\Requests\Api;

use App\Models\{Doctor, ExamType, Patient, Schedule};
use Illuminate\Foundation\Http\FormRequest;

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

        return [
            'patient_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($integrator) {
                    $query = Patient::query()
                        ->where('entity_id', $integrator->entity_id)
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
                function ($attribute, $value, $fail) use ($integrator) {
                    $query = ExamType::query()
                        ->where(function ($query) use ($integrator) {
                            $query->where('entity_id', $integrator->entity_id)
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
                function ($attribute, $value, $fail) use ($integrator) {
                    $query = Doctor::query()
                        ->with('entityUser')
                        ->whereHas('entityUser', function ($query) use ($integrator) {
                            $query->where('entity_id', $integrator->entity_id)
                                ->whereNull('deleted_at');
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
                        $fail(__('validation.custom.validation_invalid.doctor_identifier'));
                    }
                },
            ],
            'schedule_identifier' => [
                'nullable',
                function ($attribute, $value, $fail) use ($integrator) {
                    $query = Schedule::query()
                        ->where(function ($query) use ($integrator) {
                            $query->where('entity_id', $integrator->entity_id)
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
            } else {
                $data[$key] = $value;
            }
        }

        $this->merge($data);
    }
}
