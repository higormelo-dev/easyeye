<?php

namespace App\Http\Requests\Api;

use App\Models\Doctor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

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
            'patient_id' => [
                'nullable',
                'uuid',
                Rule::exists('patients', 'id')->where(function ($query) use ($integrator) {
                    return $query->where('entity_id', $integrator->entity_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'patient_code' => [
                'nullable',
                'string',
                Rule::exists('patients', 'code')->where(function ($query) use ($integrator) {
                    return $query->where('entity_id', $integrator->entity_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'doctor_id' => [
                'nullable',
                'uuid',
                function ($attribute, $value, $fail) use ($integrator) {
                    $exists = Doctor::query()
                        ->where('id', $value)
                        ->whereHas('entityUser', function ($query) use ($integrator) {
                            $query->where('entity_id', $integrator->entity_id)
                                ->whereNull('deleted_at');
                        })
                        ->whereNull('deleted_at')
                        ->exists();

                    if (! $exists) {
                        $fail('O médico selecionado é inválido.');
                    }
                },
            ],
            'doctor_code' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) use ($integrator) {
                    $exists = Doctor::query()
                        ->where('code', $value)
                        ->whereHas('entityUser', function ($query) use ($integrator) {
                            $query->where('entity_id', $integrator->entity_id)
                                ->whereNull('deleted_at');
                        })
                        ->whereNull('deleted_at')
                        ->exists();

                    if (! $exists) {
                        $fail('O médico selecionado é inválido.');
                    }
                },
            ],
            'schedule_id' => [
                'nullable',
                'uuid',
                Rule::exists('schedules', 'id')->where(function ($query) use ($integrator) {
                    return $query->where('entity_id', $integrator->entity_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'schedule_code' => [
                'nullable',
                'string',
                Rule::exists('schedules', 'code')->where(function ($query) use ($integrator) {
                    return $query->where('entity_id', $integrator->entity_id)
                        ->whereNull('deleted_at');
                }),
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

    protected function failedValidation(Validator $validator): mixed
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Dados de validação inválidos.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
