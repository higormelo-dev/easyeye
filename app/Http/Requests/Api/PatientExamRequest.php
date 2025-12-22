<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
        $rules = [
            'name'        => 'nullable|string|max:255|min:3',
            'doctor_id'   => 'nullable|uuid|exists:doctors,id',
            'schedule_id' => 'nullable|uuid|exists:schedules,id',
        ];

        // Verificar se é POST original ou se tem _method=PUT
        $isUpdate = $this->isMethod('PUT') ||
            $this->isMethod('PATCH') ||
            $this->input('_method') === 'PUT' ||
            $this->input('_method') === 'PATCH';

        if ($this->isMethod('POST') && !$isUpdate) {
            $rules['archive'] = 'required|file|mimes:jpg,jpeg,png|max:10240';
        } else {
            $rules['archive'] = 'required|file|mimes:jpg,jpeg,png|max:10240';
            $rules['active']  = 'required|boolean';
        }

        return $rules;
    }

    protected function prepareForValidation()
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
                $cleanValue = preg_replace('/^-+|-+$/', '', $cleanValue);
                $cleanValue = trim($cleanValue);

                // Se ficou vazio, definir como null
                $data[$key] = $cleanValue === '' ? null : $cleanValue;
            } else {
                $data[$key] = $value;
            }
        }

        $this->merge($data);
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Dados de validação inválidos.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
