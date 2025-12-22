<?php

namespace App\Http\Requests\Api;

use App\Models\{EntityIntegrator, EntityIntegratorEquipment};
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
        $integrator = EntityIntegrator::query()->where('token_session', request()->bearerToken())->first();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'ip'  => ['required', 'ip', 'custom_unique_ip'],
            'mac' => [
                'required',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
                'custom_unique_mac',
            ],
            'serial_number' => [
                'required',
                'string',
                'max:100',
            ],

        ];
    }

    public function withValidator($validator): void
    {
        $validator->addExtension('custom_unique_ip', function ($attribute, $value, $parameters, $validator) {
            // Buscar integrador pelo token
            $integrator = EntityIntegrator::query()->where('token_session', request()->bearerToken())->first();

            if (!$integrator) {
                return false;
            }

            $query = EntityIntegratorEquipment::query()->where('integrator_id', $integrator->id)
                ->where('ip', $value);

            if ($this->route('equipment')) {
                $query->where('id', '!=', $this->route('equipment'));
            }

            return !$query->exists();
        });

        $validator->addExtension('custom_unique_mac', function ($attribute, $value, $parameters, $validator) {
            // Buscar integrador pelo token
            $integrator = EntityIntegrator::query()->where('token_session', request()->bearerToken())->first();

            if (!$integrator) {
                return false;
            }

            $query = EntityIntegratorEquipment::query()->where('integrator_id', $integrator->id)
                ->where('mac', $value);

            if ($this->route('equipment')) {
                $query->where('id', '!=', $this->route('equipment'));
            }

            return !$query->exists();
        });

        $validator->addReplacer('custom_unique_ip', function ($message, $attribute, $rule, $parameters) {
            return 'Este endereço IP já está sendo usado por outro equipamento.';
        });
        $validator->addReplacer('custom_unique_mac', function ($message, $attribute, $rule, $parameters) {
            return 'Este endereço MAC já está sendo usado por outro equipamento.';
        });
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Dados de validação inválidos.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }

}
