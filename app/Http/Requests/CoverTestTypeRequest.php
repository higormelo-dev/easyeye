<?php

namespace App\Http\Requests;

use App\Models\CoverTestType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CoverTestTypeRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('cover_test_types', 'name')
                    ->ignore($this->getIgnoredCoverTestTypeId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'abbreviation' => [
                'required_without:type_method',
                'string',
                'max:50',
                Rule::unique('cover_test_types', 'abbreviation')
                    ->ignore($this->getIgnoredCoverTestTypeId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without'         => trans('validation.custom.generic.required'),
            'abbreviation.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('abbreviation')) {
            $this->merge([
                'abbreviation' => mb_strtoupper($this->input('abbreviation')),
            ]);
        }

        if ($this->has('name')) {
            $this->merge([
                'name' => mb_strtoupper($this->input('name')),
            ]);
        }
    }

    private function getIgnoredCoverTestTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $coverTestTypeId = $this->route('covertesttype');

            $coverTestType = CoverTestType::query()
                ->where('cover_test_types.entity_id', session('selected_entity_id'))
                ->where('cover_test_types.id', $coverTestTypeId)
                ->first();

            return $coverTestType->id ?? null;
        }

        return null;
    }
}
