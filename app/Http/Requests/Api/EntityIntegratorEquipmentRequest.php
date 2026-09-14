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
     */
    private function uniqueRule(string $column): Unique
    {
        $param    = $this->route('equipment');
        $ignoreId = match (true) {
            $param === null              => null,
            Str::isUuid((string) $param) => $param,
            ctype_digit((string) $param) => EntityIntegratorEquipment::where(
                'code',
                sprintf('EIQ-%010d', (int) $param),
            )->value('id'),
            default => EntityIntegratorEquipment::where('code', $param)->value('id'),
        };

        return Rule::unique(self::TABLE, $column)
            ->ignore($ignoreId)
            ->whereNull('deleted_at')
            ->where('integrator_id', request()->attributes->get('integrator')->id);
    }
}
