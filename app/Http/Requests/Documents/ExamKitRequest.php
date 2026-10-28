<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Services\Documents\ExamKitService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Kit de exames (Fase 2 / B2) — clínica (Configurações › Kits de exames) e
 * manager (kits padrão). Convênios só no kit da clínica; existência, tenant e
 * "ativo" dos convênios e procedimentos são conferidos no ExamKitService.
 */
class ExamKitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'min:2', 'max:120'],
            'description'          => ['nullable', 'string', 'max:500'],
            'active'               => ['nullable', 'boolean'],
            'items'                => ['required', 'array', 'min:1', 'max:' . ExamKitService::MAX_ITEMS],
            'items.*'              => ['array'],
            'items.*.name'         => ['required', 'string', 'min:2', 'max:160'],
            'items.*.tuss_code'    => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'items.*.procedure_id' => ['nullable', 'uuid'],
            'covenant_ids'         => ['nullable', 'array', 'max:300'],
            'covenant_ids.*'       => ['uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'              => __('clinical_documents.kits.name'),
            'description'       => __('clinical_documents.kits.description'),
            'items'             => __('clinical_documents.kits.items'),
            'items.*.name'      => __('clinical_documents.kits.item_name'),
            'items.*.tuss_code' => __('clinical_documents.kits.item_code'),
            'covenant_ids'      => __('clinical_documents.kits.covenants'),
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => __('clinical_documents.kits.items_required'),
            'items.min'      => __('clinical_documents.kits.items_required'),
            'items.max'      => __('clinical_documents.kits.items_max', ['max' => ExamKitService::MAX_ITEMS]),
        ];
    }
}
