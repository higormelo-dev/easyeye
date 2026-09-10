<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entityId   = session('selected_entity_id');
        $supplierId = $this->route('supplier')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            // GAP fechado (revisão pós-Fase 4): documento aceitava QUALQUER
            // string até 20 chars — agora exige 11 (CPF, fornecedor PF/MEI)
            // ou 14 (CNPJ, fornecedor PJ) dígitos, já normalizado (sem
            // pontuação) no prepareForValidation() abaixo. Mesmo nível de
            // rigor de RegisterRequest::company_cnpj — só dígitos + tamanho,
            // SEM checksum completo do dígito verificador: este projeto não
            // valida CNPJ/CPF por algoritmo em lugar nenhum hoje, manter
            // consistência em vez de inventar regra nova só aqui.
            'document' => [
                'nullable',
                'string',
                'regex:/^\d{11}$|^\d{14}$/',
                Rule::unique('suppliers', 'document')
                    ->ignore($supplierId)
                    ->where(fn ($query) => $query->where('entity_id', $entityId)->whereNull('deleted_at')),
            ],
            'email'        => ['nullable', 'email', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:20'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string', 'max:2000'],
            'active'       => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'  => trans('validation.custom.generic.required'),
            'document.regex' => __('stock.supplier_document_invalid'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('active')) {
            $this->merge(['active' => $this->normalizeBoolean($this->input('active'))]);
        }

        // Mesmo padrão de RegisterRequest::company_cnpj — normaliza ANTES de
        // validar, então "12.345.678/0001-90" e "12345678000190" batem
        // igual (usuário não precisa saber que a UI proibiu pontuação).
        if ($this->filled('document')) {
            $this->merge(['document' => preg_replace('/\D/', '', (string) $this->input('document'))]);
        }
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
