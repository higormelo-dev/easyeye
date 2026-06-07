<?php

namespace App\Http\Requests;

use App\Models\VisitType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisitTypeRequest extends FormRequest
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
                Rule::unique('visit_types', 'name')
                    ->ignore($this->getIgnoredVisitTypeId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'procedure_id' => [
                'nullable',
                'uuid',
                Rule::exists('procedures', 'id')->where(fn ($q) => $q
                    ->where(fn ($sub) => $sub->where('entity_id', session('selected_entity_id'))->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
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
            'name.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredVisitTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $visitTypeId = $this->route('visittype');

            $visitType = VisitType::query()
                ->where('visit_types.entity_id', session('selected_entity_id'))
                ->where('visit_types.id', $visitTypeId)
                ->first();

            return $visitType->id ?? null;
        }

        return null;
    }
}
