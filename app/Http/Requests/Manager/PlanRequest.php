<?php

namespace App\Http\Requests\Manager;

use App\Enums\FeatureKey;
use App\Models\PlanPrice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Só liga/desliga o plano na listagem. */
    public function isToggle(): bool
    {
        return $this->has('active') && count($this->keys()) === 1;
    }

    public function rules(): array
    {
        $isToggle = $this->isToggle();
        $sellable = PlanPrice::sellableCycleValues();

        $rules = [
            'name'        => [$isToggle ? 'sometimes' : 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            // Ciclo padrão: o que aparece primeiro no site e vira o preço de referência.
            'billing_cycle'          => [$isToggle ? 'sometimes' : 'required', Rule::in($sellable)],
            'prices'                 => [$isToggle ? 'sometimes' : 'required', 'array', 'min:1', 'max:' . count($sellable)],
            'prices.*.billing_cycle' => ['required', 'distinct', Rule::in($sellable)],
            'prices.*.price'         => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'active'                 => ['boolean'],
            'is_featured'            => ['boolean'],
            'sort_order'             => ['nullable', 'integer', 'min:0'],
            'features'               => ['nullable', 'array'],
        ];

        // Limites chegam como número (input numérico) e recursos como '0'/'1'.
        foreach (FeatureKey::cases() as $feature) {
            $rules["features.{$feature->value}"] = $feature->isBoolean()
                ? ['sometimes', 'required', Rule::in(['0', '1'])]
                : ['sometimes', 'required', 'integer', 'min:0', 'max:1000000000'];
        }

        return $rules;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->isToggle() || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $offered = collect($this->input('prices', []))->pluck('billing_cycle')->all();

                if (! in_array($this->input('billing_cycle'), $offered, true)) {
                    $validator->errors()->add('billing_cycle', __('manager_plans.validation.default_cycle_not_offered'));
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                   => __('manager_plans.validation.name_required'),
            'billing_cycle.required'          => __('manager_plans.validation.cycle_required'),
            'billing_cycle.in'                => __('manager_plans.validation.cycle_invalid'),
            'prices.required'                 => __('manager_plans.validation.prices_required'),
            'prices.min'                      => __('manager_plans.validation.prices_required'),
            'prices.*.billing_cycle.distinct' => __('manager_plans.validation.cycle_duplicated'),
            'prices.*.billing_cycle.in'       => __('manager_plans.validation.cycle_invalid'),
            'prices.*.price.required'         => __('manager_plans.validation.price_required'),
            'prices.*.price.numeric'          => __('manager_plans.validation.price_invalid'),
            'prices.*.price.min'              => __('manager_plans.validation.price_invalid'),
            'features.*.required'             => __('manager_plans.validation.feature_required'),
            'features.*.integer'              => __('manager_plans.validation.feature_integer'),
            'features.*.min'                  => __('manager_plans.validation.feature_integer'),
        ];
    }
}
