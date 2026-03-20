<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueRule('name')],
            // 'ip'   => ['required', 'ip', $this->uniqueRule('ip')],
            'ip'  => ['required', 'ip'],
            'mac' => [
                'required',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
                $this->uniqueRule('mac'),
            ],
            'serial_number' => ['required', 'string', 'max:100', $this->uniqueRule('serial_number')],
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
     * Regra unique com escopo do integrador
     */
    private function uniqueRule(string $column): Unique
    {
        return Rule::unique(self::TABLE, $column)
            ->ignore($this->route('equipment'))
            ->whereNull('deleted_at')
            ->where('integrator_id', request()->attributes->get('integrator')->id);
    }
}
