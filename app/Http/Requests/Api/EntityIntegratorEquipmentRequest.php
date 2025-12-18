<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityIntegratorEquipmentRequest extends FormRequest
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
                'required',
                'string',
                'max:255',
                /*Rule::unique('entity_integrator_equipments')->where(function ($query) {
                    $query = $query->where('entity_id', $this->entity);

                    if ($this->integrator) {
                        $query = $query->where('id', '!=', $this->integrator);
                    }

                    return $query;
                }),*/
            ],
            'ip' => [
                'required',
                'ip',
            ],
            'mac' => [
                'required',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
            ],
            'serial_number' => [
                'required',
                'string',
                'max:100',
            ],

        ];
    }
}
