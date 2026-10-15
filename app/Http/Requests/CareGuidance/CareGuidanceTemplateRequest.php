<?php

declare(strict_types=1);

namespace App\Http\Requests\CareGuidance;

use App\Domains\CareGuidance\Services\{CareGuidanceIssueService, CareGuidanceTemplateService};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modelo de orientação pós-procedimento (Configurações). Acesso: grupo `permission:settings.manage` da rota. O
 * procedimento é do catálogo da clínica ou global (mesma regra do pedido de procedimento).
 */
class CareGuidanceTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entityId = session('selected_entity_id');

        return [
            'title'        => ['required', 'string', 'max:160'],
            'procedure_id' => [
                'nullable',
                'uuid',
                Rule::exists('procedures', 'id')
                    ->where(fn ($query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at'),
            ],
            'location_label'            => ['nullable', 'string', 'max:160'],
            'body'                      => ['required', 'string', 'max:' . CareGuidanceIssueService::MAX_BODY],
            'active'                    => ['nullable', 'boolean'],
            'eye_drops'                 => ['nullable', 'array', 'max:' . CareGuidanceTemplateService::MAX_DROPS],
            'eye_drops.*.name'          => ['nullable', 'string', 'max:120'],
            'eye_drops.*.how'           => ['nullable', 'string', 'max:200'],
            'eye_drops.*.times_per_day' => ['nullable', 'integer', 'between:1,12'],
            'eye_drops.*.days'          => ['nullable', 'integer', 'between:1,90'],
        ];
    }
}
