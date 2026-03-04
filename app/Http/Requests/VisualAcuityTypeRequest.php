<?php

namespace App\Http\Requests;

use App\Models\{VisualAcuityType};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisualAcuityTypeRequest extends FormRequest
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
                Rule::unique('visual_acuity_types', 'name')
                    ->ignore($this->getIgnoredVisualAcuityTypeId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'scale' => [
                'required_without:type_method',
                'integer',
                'min:0',
                'max:100',
                Rule::unique('visual_acuity_types', 'scale')
                    ->ignore($this->getIgnoredVisualAcuityTypeId(), 'id')
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

    private function getIgnoredVisualAcuityTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $visualAcuityTypeId = $this->route('visualacuitytype');

            $visualAcuityType = VisualAcuityType::query()
                ->where('visual_acuity_types.entity_id', session('selected_entity_id'))
                ->where('visual_acuity_types.id', $visualAcuityTypeId)
                ->first();

            return $visualAcuityType->id ?? null;
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
