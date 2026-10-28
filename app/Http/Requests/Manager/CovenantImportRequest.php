<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sincronização do catálogo de convênios com a ANS: baixando direto da ANS
 * (padrão) ou enviando os CSVs (ANS fora do ar). Modalidades escolhidas
 * limitam só as operadoras NOVAS — as que já estão no catálogo sempre
 * recebem os dados atualizados.
 */
class CovenantImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota: manager + saas.role:admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source'       => ['required', Rule::in(['ans', 'upload'])],
            'modalities'   => ['required', 'array', 'min:1'],
            'modalities.*' => ['string', Rule::in((array) config('covenants.ans.modalities'))],
            // 20 MB: a lista de canceladas tem ~1 MB.
            'active_file'    => ['required_if:source,upload', 'nullable', 'file', 'mimes:csv,txt', 'max:20480'],
            'cancelled_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:20480'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'modalities'     => __('manager_covenants.import_modalities'),
            'active_file'    => __('manager_covenants.import_active_file'),
            'cancelled_file' => __('manager_covenants.import_cancelled_file'),
        ];
    }
}
