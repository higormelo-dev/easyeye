<?php

namespace App\Http\Requests;

use App\Models\Lense;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LenseRequest extends FormRequest
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
                Rule::unique('lenses', 'name')
                    ->ignore($this->getIgnoredLenseId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'away' => [
                'required_without:type_method',
                'boolean',
                function ($attribute, $value, $fail) {
                    if (! $this->input('away') && ! $this->input('near')) {
                        $fail(
                            __(
                                'validation.at_least_one_required',
                                ['fields' => __('validation.attributes.away_or_near')],
                            ),
                        );
                    }
                },
            ],
            'near' => ['required_without:type_method', 'boolean'],
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
            'away.required_without' => trans('validation.custom.generic.required'),
            'near.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredLenseId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $lenseId = $this->route('lens');

            $lense = Lense::query()
                ->where('lenses.entity_id', session('selected_entity_id'))
                ->where('lenses.id', $lenseId)
                ->first();

            return $lense->id ?? null;
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
