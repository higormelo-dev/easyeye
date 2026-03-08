<?php

namespace App\Http\Requests;

use App\Models\NearPointConvergence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NearPointConvergenceRequest extends FormRequest
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
                Rule::unique('near_point_convergences', 'name')
                    ->ignore($this->getIgnoredAdditionTypeId(), 'id')
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

    private function getIgnoredAdditionTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $nearPointConvergenceId = $this->route('nearpointconvergence');

            $nearPointConvergence = NearPointConvergence::query()
                ->where('near_point_convergences.entity_id', session('selected_entity_id'))
                ->where('near_point_convergences.id', $nearPointConvergenceId)
                ->first();

            return $nearPointConvergence->id ?? null;
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
