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
        return [
            'name' => [
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('covenants', 'name')
                    ->ignore($this->getIgnoredCovenantId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
            'color' => [
                'required_without:type_method',
                'string',
                'regex:/^#[0-9a-fA-F]{6}$/',
                Rule::unique('covenants', 'color')
                    ->ignore($this->getIgnoredCovenantId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
            'table' => 'required_without:type_method|integer',
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
}
