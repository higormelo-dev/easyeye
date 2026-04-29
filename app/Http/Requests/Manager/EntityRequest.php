<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = $this->route('entity');

        return [
            'name'      => ['required', 'string', 'max:150'],
            'subdomain' => [
                'nullable', 'string', 'max:100', 'alpha_dash',
                Rule::unique('entities', 'subdomain')->ignore($entityId),
            ],
            'email'                  => ['nullable', 'email', 'max:150'],
            'telephone'              => ['nullable', 'string', 'max:20'],
            'cellphone'              => ['nullable', 'string', 'max:20'],
            'national_registration'  => ['nullable', 'string', 'max:20'],
            'state_registration'     => ['nullable', 'string', 'max:30'],
            'municipal_registration' => ['nullable', 'string', 'max:30'],
            'website'                => ['nullable', 'url', 'max:150'],
            'zipcode'                => ['nullable', 'string', 'max:10'],
            'address'                => ['nullable', 'string', 'max:200'],
            'number'                 => ['nullable', 'string', 'max:10'],
            'complement'             => ['nullable', 'string', 'max:100'],
            'district'               => ['nullable', 'string', 'max:100'],
            // Localização obrigatória — usada em PDFs clínicos. Sistema é multi-idioma:
            // state pode ser sigla (UF brasileira) ou nome completo (estados estrangeiros).
            'city'              => ['required', 'string', 'max:100'],
            'state'             => ['required', 'string', 'max:50'],
            'country'           => ['required', 'string', 'max:50'],
            'schedule_interval' => ['integer', 'in:15,20,30'],
            'active'            => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'O nome da empresa é obrigatório.',
            'subdomain.unique' => 'Este subdomínio já está em uso.',
            'email.email'      => 'Informe um e-mail válido.',
            'city.required'    => 'A cidade da clínica é obrigatória (impressa em todos os documentos clínicos).',
            'state.required'   => 'O estado/UF da clínica é obrigatório.',
            'country.required' => 'O país da clínica é obrigatório.',
        ];
    }
}
