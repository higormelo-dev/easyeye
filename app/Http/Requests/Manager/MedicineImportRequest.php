<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Carga do catálogo de medicamentos: download direto das fontes oficiais
 * (source=cmed) ou envio da lista de preços CMED (obrigatória no envio — traz
 * as apresentações) e, opcionalmente, do DADOS_ABERTOS_MEDICAMENTOS.csv da
 * Anvisa (filtra registros inativos). Ver MedicineCatalogSyncService.
 */
class MedicineImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Sem origem (cliente antigo/API) = envio de arquivo, como sempre foi.
        if (! $this->filled('source')) {
            $this->merge(['source' => 'upload']);
        }
    }

    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(['cmed', 'upload'])],
            // 50 MB: a lista CMED completa tem ~12 MB em XLSX.
            'cmed_file'      => ['required_if:source,upload', 'nullable', 'file', 'mimes:xlsx,xls,csv,txt', 'max:51200'],
            'open_data_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:51200'],
            // Download: reprocessa mesmo que a lista e os dados abertos não tenham mudado.
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'cmed_file'      => __('manager_medicines.import_cmed_file'),
            'open_data_file' => __('manager_medicines.import_open_data_file'),
        ];
    }
}
