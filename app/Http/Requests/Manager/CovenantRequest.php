<?php

namespace App\Http\Requests\Manager;

use App\Support\BrazilianFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Convênio do catálogo GLOBAL (manager → Convênios). Cadastro manual:
 * todos os campos; convênio da ANS: o controller só aceita nome de exibição,
 * cor, tabela própria e ativo (o resto vem da sincronização).
 *
 * Nome e registro ANS únicos entre os convênios globais — várias rotinas
 * (importação de pacientes e agenda) casam convênio pelo nome.
 */
class CovenantRequest extends FormRequest
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function authorize(): bool
    {
        return true; // rota: manager + saas.role:admin
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            // Mesmo formato gravado pelo model (HasUppercaseFields) — a
            // unicidade compara o valor salvo.
            $merge['name'] = mb_strtoupper(trim((string) $this->input('name')), 'UTF-8');
        }

        if ($this->has('national_registry') && $this->input('national_registry') !== null) {
            // Mantém letras: CNPJ alfanumérico (IN RFB 2.229/2024).
            $merge['national_registry'] = BrazilianFormat::documentChars((string) $this->input('national_registry'));
        }

        if ($this->has('ans_registry') && $this->input('ans_registry') !== null) {
            $merge['ans_registry'] = BrazilianFormat::digits((string) $this->input('ans_registry'));
        }

        if ($this->has('uf') && $this->input('uf') !== null) {
            $merge['uf'] = mb_strtoupper(trim((string) $this->input('uf')));
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id       = $this->route('covenant');
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        $globalUnique = fn (string $column) => Rule::unique('covenants', $column)
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->ignore($id);

        return [
            'name'         => [$required, 'string', 'max:255', $globalUnique('name')],
            'company_name' => ['nullable', 'string', 'max:255'],
            'trade_name'   => ['nullable', 'string', 'max:255'],
            // 14 posições, as 12 primeiras podem ter letras (CNPJ alfanumérico);
            // sem dígito verificador — mesmo rigor do resto do projeto.
            'national_registry' => ['nullable', 'string', 'regex:/^[A-Z0-9]{12}\d{2}$/'],
            'ans_registry'      => ['nullable', 'string', 'regex:/^\d{6}$/', $globalUnique('ans_registry')],
            'ans_modality'      => ['nullable', 'string', Rule::in((array) config('covenants.ans.modalities'))],
            'city'              => ['nullable', 'string', 'max:120'],
            'uf'                => ['nullable', 'string', Rule::in(self::UFS)],
            'color'             => [$required, 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'table'             => ['sometimes', 'boolean'],
            'active'            => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name'              => __('manager_covenants.field_name'),
            'company_name'      => __('manager_covenants.field_company_name'),
            'trade_name'        => __('manager_covenants.field_trade_name'),
            'national_registry' => __('manager_covenants.field_cnpj'),
            'ans_registry'      => __('manager_covenants.field_ans_registry'),
            'ans_modality'      => __('manager_covenants.field_modality'),
            'city'              => __('manager_covenants.field_city'),
            'uf'                => __('manager_covenants.field_uf'),
            'color'             => __('manager_covenants.field_color'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'national_registry.regex' => __('manager_covenants.cnpj_invalid'),
            'ans_registry.regex'      => __('manager_covenants.ans_registry_invalid'),
            'name.unique'             => __('manager_covenants.name_taken'),
            'ans_registry.unique'     => __('manager_covenants.ans_registry_taken'),
        ];
    }
}
