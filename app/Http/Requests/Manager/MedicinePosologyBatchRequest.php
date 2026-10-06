<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Enums\AI\AiProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Prévia/início do lote "Gerar posologia com IA" (Manager → Medicamentos):
 * os MESMOS filtros da listagem (normalizados por MedicineCatalogFilters) +
 * a IA escolhida (obrigatória quando há mais de uma — o serviço confere).
 */
class MedicinePosologyBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota: manager + saas.role:admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search'         => ['nullable', 'string', 'max:200'],
            'source'         => ['nullable', 'string', 'max:20'],
            'status'         => ['nullable', 'string', 'max:20'],
            'cmed_situation' => ['nullable', 'string', 'max:20'],
            'posology'       => ['nullable', 'string', 'max:20'],
            'ophthalmic'     => ['nullable', 'boolean'],
            'sort'           => ['nullable', 'string', 'max:30'],
            'direction'      => ['nullable', 'string', 'max:4'],
            'provider'       => ['nullable', 'string', Rule::in(array_column(AiProvider::cases(), 'value'))],
        ];
    }
}
