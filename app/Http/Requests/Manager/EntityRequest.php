<?php

namespace App\Http\Requests\Manager;

use App\Support\BrazilianFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Front envia CEP/telefones mascarados (v-mask); banco guarda só dígitos
     * (mesmo formato do RegisterRequest e do Entity::setAttribute).
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('zipcode') && $this->input('zipcode') !== null) {
            $merge['zipcode'] = preg_replace('/\D/', '', (string) $this->input('zipcode')) ?: null;
        }

        // Telefones: só dígitos e sem DDI 55 — Pagar.me/PagBank usam os 2 primeiros
        // dígitos como área. Mesma regra do DoctorRequest (BrazilianFormat::canonicalPhone).
        foreach (['telephone', 'cellphone'] as $phoneField) {
            if ($this->has($phoneField) && $this->input($phoneField) !== null) {
                $merge[$phoneField] = BrazilianFormat::canonicalPhone((string) $this->input($phoneField));
            }
        }

        // CNPJ pode ser alfanumérico (IN RFB 2.229/2024): tira só a pontuação e
        // mantém as letras, igual Entity::setAttribute — nunca \D aqui.
        if ($this->has('national_registration') && $this->input('national_registration') !== null) {
            $merge['national_registration'] = BrazilianFormat::documentChars((string) $this->input('national_registration'));
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $entityId = $this->route('entity');

        return [
            'name'      => ['required_without:type_method', 'string', 'max:150'],
            'subdomain' => [
                'nullable', 'string', 'max:100', 'alpha_dash',
                Rule::unique('entities', 'subdomain')->ignore($entityId),
            ],
            'email'                  => ['nullable', 'email', 'max:150'],
            'telephone'              => ['nullable', 'string', 'max:20'],
            'cellphone'              => ['nullable', 'string', 'max:20'],
            'national_registration'  => ['nullable', 'string', 'max:20'],
            'cnes'                   => ['nullable', 'string', 'max:7'],
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
            // required_without:type_method: campos opcionais em partial updates (ex: toggle active).
            'city'              => ['required_without:type_method', 'string', 'max:100'],
            'state'             => ['required_without:type_method', 'string', 'max:50'],
            'country'           => ['required_without:type_method', 'string', 'max:50'],
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
