<?php

namespace App\Http\Requests;

use App\Models\VisitType;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('visit_types', 'name')
                    ->ignore($this->getIgnoredVisitTypeId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
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
