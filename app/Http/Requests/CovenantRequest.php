<?php

namespace App\Http\Requests;

use App\Models\Covenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CovenantRequest extends FormRequest
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
                Rule::unique('covenants', 'name')
                    ->ignore($this->getIgnoredCovenantId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'color' => [
                'required_without:type_method',
                'string',
                'regex:/^#[0-9a-fA-F]{6}$/',
                Rule::unique('covenants', 'color')
                    ->ignore($this->getIgnoredCovenantId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'table' => 'required_without:type_method|boolean',
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
            'name.required_without'  => trans('validation.custom.generic.required'),
            'color.required_without' => trans('validation.custom.generic.required'),
            'table.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredCovenantId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $covenantId = $this->route('covenant');

            $covenant = Covenant::query()
                ->where('covenants.entity_id', session('selected_entity_id'))
                ->where('covenants.id', $covenantId)
                ->first();

            return $covenant->id ?? null;
        }

        return null;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            $merge['name'] = mb_strtoupper($this->input('name'));
        }

        if ($this->has('table')) {
            $merge['table'] = $this->normalizeBoolean($this->input('table'));
        }

        if ($this->has('active')) {
            $merge['active'] = $this->normalizeBoolean($this->input('active'));
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
