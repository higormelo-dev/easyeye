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
        return [
            'covenant_id' => [
                'required_without:type_method',
                'uuid',
                'exists:covenants,id',
            ],
            'skin_id'     => 'nullable|uuid|exists:skins,id',
            'iris_id'     => 'nullable|uuid|exists:irises,id',
            'card_number' => 'nullable|string|max:255',

            'full_name' => [
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
                Rule::in([1, 2]),
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
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'covenant_id.required_without' => trans('validation.custom.generic.required'),
            'full_name'                    => trans('validation.custom.generic.required'),
            'birth_date'                   => trans('validation.custom.generic.required'),
            'gender'                       => trans('validation.custom.generic.required'),
            'marital_status'               => trans('validation.custom.generic.required'),
            'email'                        => trans('validation.custom.generic.required'),
            'national_registry'            => trans('validation.custom.generic.required'),
            'cellphone'                    => trans('validation.custom.generic.required'),
            'whatsapp'                     => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredPersonId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $patientId = $this->route('patient');

            $patient = Patient::query()
                ->with('person')
                ->where('patients.id', $patientId)
                ->first();

            return $patient && $patient->person ? $patient->person->id : null;
        }

        return null;
    }
}
