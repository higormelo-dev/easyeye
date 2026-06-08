<?php

namespace App\Http\Requests;

use App\Models\SkinType;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
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

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('active')) {
            $merge['active'] = $this->normalizeBoolean($this->input('active'));
        }

        if (! empty($merge)) {
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

        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
