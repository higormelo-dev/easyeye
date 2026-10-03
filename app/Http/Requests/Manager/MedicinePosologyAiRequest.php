<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedido de sugestão de posologia por IA (Manager → Medicamentos).
 * Item já salvo: só o id — os dados vêm do banco. Cadastro novo: os campos
 * digitados no formulário.
 */
class MedicinePosologyAiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota: manager + saas.role:admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'medicine_id'              => ['nullable', 'string', 'max:64'],
            'name'                     => ['required_without:medicine_id', 'nullable', 'string', 'max:255'],
            'active_ingredient'        => ['nullable', 'string', 'max:255'],
            'concentration'            => ['nullable', 'string', 'max:100'],
            'medicine_presentation_id' => ['nullable', 'string', 'max:64'],
            'is_ophthalmic'            => ['nullable', 'boolean'],
        ];
    }
}
