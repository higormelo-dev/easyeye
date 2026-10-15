<?php

declare(strict_types=1);

namespace App\Http\Requests\CareGuidance;

use App\Domains\CareGuidance\Services\{CareGuidanceIssueService, CareGuidanceTemplateService};
use App\Enums\EntityGate;
use App\Models\Entity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Emissão da orientação: ato do médico (Gate IssueReport, como os demais documentos entregues ao paciente). */
class IssueCareGuidanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = Entity::query()->find((string) session('selected_entity_id'));

        return $entity !== null && Gate::allows(EntityGate::IssueReport->value, $entity);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template_id'               => ['required', 'uuid'],
            'body'                      => ['nullable', 'string', 'max:' . CareGuidanceIssueService::MAX_BODY],
            'location_label'            => ['nullable', 'string', 'max:160'],
            'eye_drops'                 => ['nullable', 'array', 'max:' . CareGuidanceTemplateService::MAX_DROPS],
            'eye_drops.*.name'          => ['nullable', 'string', 'max:120'],
            'eye_drops.*.how'           => ['nullable', 'string', 'max:200'],
            'eye_drops.*.times_per_day' => ['nullable', 'integer', 'between:1,12'],
            'eye_drops.*.days'          => ['nullable', 'integer', 'between:1,90'],
        ];
    }

    /**
     * Só o que o médico mandou ajustar (campo ausente = o do modelo).
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return array_intersect_key($this->validated(), array_flip(['body', 'location_label', 'eye_drops']));
    }
}
