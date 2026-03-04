<?php

namespace App\Http\Requests;

use App\Models\ColorVisionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ColorVisionTypeRequest extends FormRequest
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
                Rule::unique('color_vision_types', 'name')
                    ->ignore($this->getIgnoredColorVisionTypeId(), 'id')
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

    private function getIgnoredColorVisionTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $colorVisionTypeId = $this->route('colorvisiontype');

            $colorVisionType = ColorVisionType::query()
                ->where('color_vision_types.entity_id', session('selected_entity_id'))
                ->where('color_vision_types.id', $colorVisionTypeId)
                ->first();

            return $colorVisionType->id ?? null;
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
