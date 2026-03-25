<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $ignoreId = ($this->isMethod('PUT') || $this->isMethod('PATCH'))
            ? $this->route('resource')
            : null;

        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('clinic_resources', 'name')
                    ->ignore($ignoreId, 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'type'        => ['required', 'in:room,equipment'],
            'description' => ['nullable', 'string', 'max:500'],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => mb_strtoupper($this->input('name'))]);
        }
    }
}
