<?php

namespace App\Http\Requests;

use App\Models\AdditionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdditionTypeRequest extends FormRequest
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
                Rule::unique('addition_types', 'name')
                    ->ignore($this->getIgnoredAdditionTypeId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
        ];
    }

    private function getIgnoredAdditionTypeId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $additionTypeId = $this->route('additiontype');

            $additionType = AdditionType::query()
                ->where('addition_types.entity_id', session('selected_entity_id'))
                ->where('addition_types.id', $additionTypeId)
                ->first();

            return $additionType->id ?? null;
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
