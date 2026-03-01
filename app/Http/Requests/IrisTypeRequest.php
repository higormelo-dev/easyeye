<?php

namespace App\Http\Requests;

use App\Models\{IrisType};
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IrisTypeRequest extends FormRequest
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
        $rules = [
            'name' => [
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('iris_types', 'name')
                    ->ignore($this->getIgnoredIrisTypeId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    private function getIgnoredIrisTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $irisTypeId = $this->route('iristype');

            $IrisType = IrisType::query()
                ->where('iris_types.entity_id', session('selected_entity_id'))
                ->where('iris_types.id', $irisTypeId)
                ->first();

            return $IrisType->id ?? null;
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
