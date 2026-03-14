<?php

namespace App\Http\Requests\Manager;

use App\Enums\BillingCycle;
use App\Enums\FeatureKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isToggle = $this->has('active') && count($this->keys()) === 1;

        return [
            'name'          => [$isToggle ? 'sometimes' : 'required', 'string', 'max:100'],
            'description'   => ['nullable', 'string', 'max:500'],
            'price'         => ['nullable', 'numeric', 'min:0'],
            'billing_cycle' => [$isToggle ? 'sometimes' : 'required', Rule::enum(BillingCycle::class)],
            'active'        => ['boolean'],
            'sort_order'    => ['nullable', 'integer', 'min:0'],
            'features'      => ['nullable', 'array'],
            'features.*'    => ['required', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'          => 'O nome do plano é obrigatório.',
            'billing_cycle.required' => 'O ciclo de cobrança é obrigatório.',
            'billing_cycle.enum'     => 'Ciclo de cobrança inválido.',
        ];
    }
}
