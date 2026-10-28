<?php

namespace App\Http\Requests\Api;

use App\Models\EntityIntegratorEquipment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class EntityIntegratorEquipmentRequest extends FormRequest
{
    private const TABLE = 'entity_integrator_equipments';

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
        // Só o nome é obrigatório: o integrador opera por pasta monitorada e
        // IP/MAC/serial nunca participam do pipeline — são metadados de
        // inventário. Muitos aparelhos reais (ex.: Topcon TRC-50DX com DSLR
        // acoplada) nem têm rede própria. Quando informados, formato e
        // unicidade continuam valendo.
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueRule('name')],
            'ip'   => ['nullable', 'ip', $this->uniqueRule('ip')],
            'mac'  => [
                'nullable',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
                $this->uniqueRule('mac'),
            ],
            'serial_number' => ['nullable', 'string', 'max:100', $this->uniqueRule('serial_number')],
            // Vínculo opcional com um recurso de agenda do tipo 'equipment'.
            // Escopado por entity_id do integrador autenticado: sem esse
            // where(), um integrador da clínica A poderia linkar um
            // clinic_resource da clínica B só adivinhando/enumerando o UUID —
            // vazamento de dado cross-tenant. type='equipment' também é
            // obrigatório: recursos do tipo 'room' não fazem sentido aqui.
            'clinic_resource_id' => [
                'nullable',
                'uuid',
                Rule::exists('clinic_resources', 'id')->where(function ($query) {
                    $query->where('entity_id', request()->attributes->get('integrator')->user->entity_id)
                        ->where('type', 'equipment');
                }),
            ],
        ];
    }

    public function messages(): array
    {
        $prefix = 'validation.custom.entity_integrator_equipment.';

        return [
            'ip.unique'            => __($prefix . 'ip_unique'),
            'mac.unique'           => __($prefix . 'mac_unique'),
            'name.unique'          => __($prefix . 'name_unique'),
            'serial_number.unique' => __($prefix . 'serial_number_unique'),
        ];
    }

    /**
     * Regra unique com escopo do integrador.
     *
     * No update, o próprio registro é ignorado PELO IDENTIFICADOR DA ROTA
     * (id ou code), sem consulta prévia. Antes o id a ignorar era buscado por
     * `code` em TODOS os tenants — como cada integrador tem seu
     * EIQ-0000000001, vinha o equipamento de outra clínica, o registro editado
     * não era ignorado e reenviar o próprio nome/IP/MAC dava 422 "já em uso".
     * O Rule::unique já filtra integrator_id do token, então ignorar por code
     * aqui é exatamente o registro que EntityIntegratorEquipmentService::
     * findByIdOrCode resolve.
     */
    private function uniqueRule(string $column): Unique
    {
        $rule = Rule::unique(self::TABLE, $column)
            ->whereNull('deleted_at')
            ->where('integrator_id', request()->attributes->get('integrator')->id);

        $param = $this->route('equipment');

        if ($param === null) {
            return $rule;
        }

        $param = (string) $param;

        return match (true) {
            Str::isUuid($param) => $rule->ignore($param),
            ctype_digit($param) => $rule->ignore(EntityIntegratorEquipment::formatCode((int) $param), 'code'),
            default             => $rule->ignore($param, 'code'),
        };
    }

    /**
     * Uppercasa name/mac/serial_number ANTES da validação.
     *
     * O model (EntityIntegratorEquipment::UPPERCASE_FIELDS) uppercasa esses
     * mesmos campos ao salvar. Sem normalizar aqui também, uniqueRule()
     * comparava o valor BRUTO do request contra a coluna já uppercased no
     * banco — o caso comum (cliente reenvia o mesmo texto que digitou, sem
     * estar em caixa alta) escapava da checagem de unicidade mesmo colidindo
     * com um registro existente após a normalização do model, permitindo
     * duplicata funcional de name/serial_number (achado ao escrever os
     * testes desta rota; mac não é afetado pois a coluna é macaddr nativo do
     * Postgres, que já compara endereços independente de caixa).
     */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'mac', 'serial_number'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => mb_strtoupper((string) $this->input($field), 'UTF-8')]);
            }
        }
    }
}
