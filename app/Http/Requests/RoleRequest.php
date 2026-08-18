<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação de criação/edição de Role customizada (RBAC granular ADITIVO).
 *
 * Autorização: delegada inteiramente ao middleware de rota `entity.role:admin`
 * (gestão do próprio RBAC é a "chave do cofre", nunca delegável — ver
 * App\Http\Controllers\AccessControl\RolesController). Por isso authorize()
 * apenas retorna true, mesmo padrão já usado em CovenantRequest.
 */
class RoleRequest extends FormRequest
{
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
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->where(fn ($query) => $query->where('entity_id', session('selected_entity_id')))
                    ->ignore($this->getIgnoredRoleId(), 'id'),
            ],
            'description'      => ['nullable', 'string'],
            'permission_ids'   => ['array'],
            'permission_ids.*' => ['string', Rule::exists('permissions', 'id')],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'           => trans('validation.custom.generic.required'),
            'name.unique'             => 'Já existe um perfil com este nome nesta clínica.',
            'permission_ids.*.exists' => 'Uma ou mais permissões selecionadas são inválidas.',
        ];
    }

    /**
     * Resolve o id da Role atual em edição, pro Rule::unique ignorar — cobre
     * tanto route model binding (Route::resource com type-hint Role no
     * controller) quanto o parâmetro chegando como string/uuid.
     */
    private function getIgnoredRoleId(): ?string
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role->id : $role;
    }
}
