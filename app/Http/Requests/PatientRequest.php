<?php

namespace App\Http\Requests;

use App\Models\{Patient};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientRequest extends FormRequest
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
            'covenant_id' => [
                'required_without:type_method',
                'uuid',
                'exists:covenants,id',
            ],
            'skin_id' => [
                'nullable',
                'uuid',
                Rule::exists('skin_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'iris_id' => [
                'nullable',
                'uuid',
                Rule::exists('iris_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'card_number' => 'nullable|string|max:255',
            'name'        => [
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('people', 'full_name')
                    ->ignore($this->getIgnoredPersonId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
            'nickname' => [
                'nullable',
                'string',
                'max:255',
            ],
            'birth_date' => [
                'required_without:type_method',
                'date_format:Y-m-d',
            ],
            'gender' => [
                'required_without:type_method',
                Rule::in([0, 1]),
            ],
            'marital_status' => [
                'required_without:type_method',
                Rule::in([1, 2, 3, 4, 5, 6, 7, 8]),
            ],
            'email' => [
                'required_without:type_method',
                'string',
                'max:255',
                'email:rfc,dns',
                Rule::unique('people', 'email')
                    ->ignore($this->getIgnoredPersonId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
            'mother_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'father_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'national_registry' => [
                'required_without:type_method',
                'string',
                Rule::unique('people', 'national_registry')
                    ->ignore($this->getIgnoredPersonId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
            'state_registry' => [
                'nullable',
                'string',
                'max:255',
            ],
            'state_registry_agency' => [
                'nullable',
                'string',
                'max:255',
            ],
            'state_registry_initial' => [
                'nullable',
                'string',
                Rule::in(['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES',
                    'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE',
                    'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE',
                    'TO']),
            ],
            'state_registry_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'telephone' => [
                'nullable',
                'string',
                'max:255',
            ],
            'cellphone' => [
                'required_without:type_method',
                'string',
                'max:255',
            ],
            'whatsapp' => [
                'required_without:type_method',
                'boolean',
            ],
            'zipcode' => [
                'nullable',
                'string',
                'max:255',
            ],
            'address' => [
                'nullable',
                'string',
                'max:255',
            ],
            'number' => [
                'nullable',
                'string',
                'max:255',
            ],
            'complement' => [
                'nullable',
                'string',
                'max:255',
            ],
            'district' => [
                'nullable',
                'string',
                'max:255',
            ],
            'city' => [
                'nullable',
                'string',
                'max:255',
            ],
            'state' => [
                'nullable',
                'string',
                'max:255',
            ],
            'country' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'covenant_id.required_without'       => __('validation.custom.generic.required'),
            'full_name.required_without'         => __('validation.custom.generic.required'),
            'birth_date.required_without'        => __('validation.custom.generic.required'),
            'gender.required_without'            => __('validation.custom.generic.required'),
            'marital_status.required_without'    => __('validation.custom.generic.required'),
            'email.required_without'             => __('validation.custom.generic.required'),
            'national_registry.required_without' => __('validation.custom.generic.required'),
            'cellphone.required_without'         => __('validation.custom.generic.required'),
            'whatsapp.required_without'          => __('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredPersonId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $patientId = $this->route('patient');

            $patient = Patient::query()
                ->where('id', $patientId)
                ->first();

            return $patient->person_id ?? null;
        }

        return null;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            $merge['name'] = mb_strtoupper($this->input('name'));
        }

        if ($this->has('nickname')) {
            $merge['nickname'] = mb_strtoupper($this->input('nickname'));
        }

        if ($this->has('mother_name')) {
            $merge['mother_name'] = mb_strtoupper($this->input('mother_name'));
        }

        if ($this->has('father_name')) {
            $merge['father_name'] = mb_strtoupper($this->input('father_name'));
        }

        if ($this->has('national_registry')) {
            $merge['national_registry'] = preg_replace('/\D/', '', $this->input('national_registry'));
        }

        foreach (['telephone', 'cellphone', 'zipcode'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $merge[$field] = preg_replace('/\D/', '', $this->input($field));
            }
        }

        foreach (['whatsapp', 'active'] as $booleanField) {
            if ($this->has($booleanField)) {
                $merge[$booleanField] = $this->normalizeBoolean($this->input($booleanField));
            }
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
