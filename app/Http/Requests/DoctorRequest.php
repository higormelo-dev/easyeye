<?php

namespace App\Http\Requests;

use App\Models\{Doctor};
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class DoctorRequest extends FormRequest
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules                      = [];
        $rules['name']              = ['required_without:type_method', 'string', 'min:2', 'max:255'];
        $rules['national_registry'] = [
            'required_without:type_method',
            'string',
            'max:11',
            Rule::unique('people', 'national_registry')
                ->ignore($this->getIgnoredPersonId(), 'id')
                ->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
        ];
        $rules['nickname'] = ['required_without:type_method', 'string', 'min:2', 'max:255'];
        $rules['record']   = [
            'required_without:type_method',
            'string',
            'min:2',
            'max:255',
            Rule::unique('doctors', 'record')
                ->ignore($this->route('doctor'))
                ->where(function ($query) {
                    $query->whereIn('entity_user_id', function ($subquery) {
                        $subquery->select('id')
                            ->from('entity_users')
                            ->where('entity_id', session('selected_entity_id'));
                    })->whereNull('deleted_at');
                }),
        ];
        $rules['record_specialty'] = [
            'required_without:type_method',
            'string',
            'min:2',
            'max:255',
            Rule::unique('doctors', 'record_specialty')
                ->ignore($this->route('doctor'))
                ->where(function ($query) {
                    $query->whereIn('entity_user_id', function ($subquery) {
                        $subquery->select('id')
                            ->from('entity_users')
                            ->where('entity_id', session('selected_entity_id'));
                    })->whereNull('deleted_at');
                }),
        ];
        $rules['color'] = [
            'required_without:type_method',
            'string',
            'max:255',
            Rule::unique('doctors', 'color')
                ->ignore($this->route('doctor'))
                ->where(function ($query) {
                    $query->whereIn('entity_user_id', function ($subquery) {
                        $subquery->select('id')
                            ->from('entity_users')
                            ->where('entity_id', session('selected_entity_id'));
                    })->whereNull('deleted_at');
                }),
        ];
        $rules['birth_date']     = ['nullable', 'date'];
        $rules['gender']         = ['nullable', 'integer'];
        $rules['marital_status'] = ['nullable', 'integer'];
        $rules['email']          = [
            'required_without:type_method',
            'string',
            'max:255',
            Rule::unique('people', 'email')
                ->ignore($this->getIgnoredPersonId(), 'id')
                ->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
        ];
        $rules['mother_name']            = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['father_name']            = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state_registry']         = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state_registry_agency']  = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state_registry_initial'] = ['nullable', 'string'];
        $rules['state_registry_date']    = ['nullable', 'date'];
        $rules['telephone']              = ['nullable', 'string'];
        $rules['cellphone']              = ['nullable', 'string'];
        $rules['whatsapp']               = ['nullable', 'boolean'];
        $rules['zipcode']                = ['nullable', 'string', 'min:2', 'max:20'];
        $rules['address']                = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['number']                 = ['nullable', 'string', 'min:2', 'max:50'];
        $rules['complement']             = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['district']               = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['city']                   = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state']                  = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['observation']            = ['nullable', 'string'];
        $rules['partner']                = ['nullable', 'boolean'];

        if ($this->isMethod('POST')) {
            $rules['password'] = [
                'required_without:type_method',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ];
            $rules['password_confirmation'] = [
                'required_without:type_method',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ];
        } elseif ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
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
            'record.required_without'                => trans('validation.custom.generic.required'),
            'record_specialty.required_without'      => trans('validation.custom.generic.required'),
            'color.required_without'                 => trans('validation.custom.generic.required'),
            'name.required_without'                  => trans('validation.custom.generic.required'),
            'nickname.required_without'              => trans('validation.custom.generic.required'),
            'birth_date.required_without'            => trans('validation.custom.generic.required'),
            'gender.required_without'                => trans('validation.custom.generic.required'),
            'marital_status.required_without'        => trans('validation.custom.generic.required'),
            'email.required_without'                 => trans('validation.custom.generic.required'),
            'national_registry.required_without'     => trans('validation.custom.generic.required'),
            'cellphone.required_without'             => trans('validation.custom.generic.required'),
            'whatsapp.required_without'              => trans('validation.custom.generic.required'),
            'password.required_without'              => trans('validation.custom.generic.required'),
            'password_confirmation.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredPersonId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $doctorId = $this->route('doctor');

            $doctor = Doctor::query()
                ->with('person')
                ->where('doctors.id', $doctorId)
                ->first();

            return $doctor && $doctor->person ? $doctor->person->id : null;
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

        if ($this->has('color')) {
            $merge['color'] = mb_strtoupper($this->input('color'));
        }

        foreach (['whatsapp', 'partner', 'active'] as $booleanField) {
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
