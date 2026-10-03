<?php

namespace App\Http\Requests;

use App\Models\{Covenant, CovenantPlan};
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Plano próprio da clínica (Configurações → Convênios → Planos). Convênio
 * visível para a clínica (global ou dela) e ativo; nome único no convênio
 * entre os planos da clínica.
 */
class CovenantPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota: permission:settings.manage
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            $merge['name'] = mb_strtoupper(trim((string) $this->input('name')), 'UTF-8');
        }

        if ($this->has('ans_code') && $this->input('ans_code') !== null) {
            $merge['ans_code'] = preg_replace('/\D/', '', (string) $this->input('ans_code'));
        }

        if ($this->has('active')) {
            $merge['active'] = filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input('active');
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');
        $updating = $this->isMethod('put') || $this->isMethod('patch');
        $current  = $updating ? $this->currentPlan($entityId) : null;
        // Edição não troca o convênio: a unicidade usa o do próprio plano.
        $covenantId = $current?->covenant_id ?? $this->input('covenant_id');

        return [
            // Edição: o formulário reenvia o convênio, mas ele não pode mudar.
            'covenant_id' => $updating ? ['sometimes', Rule::in([(string) $current?->covenant_id])] : [
                'required', 'uuid',
                Rule::exists('covenants', 'id')
                    ->whereNull('deleted_at')
                    ->where('active', true)
                    ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId)),
                // Particular não tem plano (o cadastro do paciente zera).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $name = Str::isUuid((string) $value)
                        ? Covenant::withoutGlobalScopes()->whereKey($value)->value('name')
                        : null;

                    if ($name !== null && mb_strtoupper(trim((string) $name), 'UTF-8') === 'PARTICULAR') {
                        $fail(__('covenant_plans.particular'));
                    }
                },
            ],
            'name' => [
                $updating ? 'sometimes' : 'required', 'string', 'max:255',
                Rule::unique('covenant_plans', 'name')
                    ->where('entity_id', $entityId)
                    ->where('covenant_id', $covenantId)
                    ->whereNull('deleted_at')
                    ->ignore($current?->id),
            ],
            'ans_code' => ['nullable', 'string', 'max:30'],
            'active'   => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'covenant_id' => __('covenant_plans.field_covenant'),
            'name'        => __('covenant_plans.field_name'),
            'ans_code'    => __('covenant_plans.field_ans_code'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique'    => __('covenant_plans.name_taken'),
            'covenant_id.in' => __('covenant_plans.covenant_locked'),
        ];
    }

    private function currentPlan(string $entityId): ?CovenantPlan
    {
        $id = (string) $this->route('covenant_plan');

        return CovenantPlan::withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->when(Str::isUuid($id), fn ($q) => $q->where('id', $id), fn ($q) => $q->where('code', $id))
            ->first();
    }
}
