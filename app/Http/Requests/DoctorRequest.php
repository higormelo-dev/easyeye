<?php

namespace App\Http\Requests;

use App\Models\{Doctor};
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
                    return $query->whereNull('deleted_at');
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
                    return $query->whereNull('deleted_at');
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
                    return $query->whereNull('deleted_at');
                }),
        ];
        $rules['color'] = [
            'required_without:type_method',
            'string',
            'max:255',
            Rule::unique('doctors', 'color')
                ->ignore($this->route('doctor'))
                ->where(function ($query) {
                    return $query->whereNull('deleted_at');
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
                    return $query->whereNull('deleted_at');
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

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
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
}
