<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload da lista de preços CMED (obrigatória — traz as apresentações) e,
 * opcionalmente, do DADOS_ABERTOS_MEDICAMENTOS.csv da Anvisa (filtra
 * registros inativos). Ver AnvisaMedicineImportService.
 */
class MedicineImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 50 MB: a lista CMED completa tem ~12 MB em XLSX.
            'cmed_file'      => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:51200'],
            'open_data_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:51200'],
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
