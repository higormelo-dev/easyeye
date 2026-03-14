<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityIntegratorRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('entity_integrators')->where(function ($query) {
                    if ($this->integrator) {
                        $query = $query->where('id', '!=', $this->integrator)
                            ->whereNull('deleted_at');
                    }

                    return $query;
                }),
            ],
            'ip' => [
                'required',
                'ip',
            ],
            'mac' => [
                'required',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => mb_strtoupper($this->input('name')),
            ]);
        }

        if ($this->has('mac')) {
            $this->merge([
                'mac' => mb_strtoupper($this->input('mac')),
            ]);
        }
    }
}
