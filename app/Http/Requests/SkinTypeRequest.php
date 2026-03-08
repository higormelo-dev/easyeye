<?php

namespace App\Http\Requests;

use App\Models\SkinType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SkinTypeRequest extends FormRequest
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
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('skin_types', 'name')
                    ->ignore($this->getIgnoredSkinTypeId(), 'id')
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
            'name.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredSkinTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $skinTypeId = $this->route('skintype');

            $skinType = SkinType::query()
                ->where('skin_types.entity_id', session('selected_entity_id'))
                ->where('skin_types.id', $skinTypeId)
                ->first();

            return $skinType->id ?? null;
        }

        return null;
    }
}
