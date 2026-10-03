<?php

namespace App\Http\Requests\Manager;

use App\Models\CovenantPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plano manual de um convênio do catálogo GLOBAL (manager). Nome único
 * dentro do convênio (entre os globais), registro do produto na ANS opcional.
 */
class CovenantPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota: manager + saas.role:admin
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            // Mesmo formato gravado pelo model (HasUppercaseFields).
            $merge['name'] = mb_strtoupper(trim((string) $this->input('name')), 'UTF-8');
        }

        if ($this->has('ans_code') && $this->input('ans_code') !== null) {
            $merge['ans_code'] = preg_replace('/\D/', '', (string) $this->input('ans_code'));
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $planId = $this->route('plan');
        // Cadastro: convênio da rota; edição: o do próprio plano.
        $covenantId = $this->route('covenant')
            ?? ($planId ? CovenantPlan::withoutGlobalScopes()->whereKey($planId)->value('covenant_id') : null);
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [
                $required, 'string', 'max:255',
                Rule::unique('covenant_plans', 'name')
                    ->whereNull('entity_id')
                    ->whereNull('deleted_at')
                    ->where('covenant_id', $covenantId)
                    ->ignore($planId),
            ],
            'ans_code' => ['nullable', 'string', 'max:30'],
            'active'   => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name'     => __('covenant_plans.field_name'),
            'ans_code' => __('covenant_plans.field_ans_code'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => __('covenant_plans.name_taken')];
    }
}
