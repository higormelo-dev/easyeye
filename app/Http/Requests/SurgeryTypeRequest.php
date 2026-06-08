<?php

namespace App\Http\Requests;

use App\Models\SurgeryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SurgeryTypeRequest extends FormRequest
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
            'category' => 'required_without:type_method|integer|in:0,1,2,3,4,5,6,7,8,9,10,11,12,13',
            'name'     => [
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('surgery_types', 'name')
                    ->ignore($this->getIgnoredSurgeryTypeId(), 'id')
                    ->withoutTrashed()
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->where('category', (int) $this->input('category'))
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
            'category.required_without' => trans('validation.custom.generic.required'),
            'name.required_without'     => trans('validation.custom.generic.required'),
            'name.unique'               => __('validation.custom.surgery_type.name_unique'),
        ];
    }

    private function getIgnoredSurgeryTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $surgeryTypeId = $this->route('surgerytype');

            $surgeryType = SurgeryType::query()
                ->where(
                    [
                        ['surgery_types.entity_id', session('selected_entity_id')],
                        ['surgery_types.id', $surgeryTypeId],
                    ],
                )
                ->first();

            return $surgeryType->id ?? null;
        }

        return null;
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
    }
}
