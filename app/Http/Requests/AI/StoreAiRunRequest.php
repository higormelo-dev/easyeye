<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\FeatureKey;
use App\Http\Requests\BaseRequest;
use App\Services\FeatureGateService;

/**
 * Valida o payload de criação de execução de IA.
 *
 * As regras semânticas (force system prompt por workflow, autorizar exam_ids,
 * validar ownership cross-tenant, aplicar guardrails de prompt) ficam fora deste
 * request: rodam no AiPayloadEnricher para serem testadas em isolamento.
 */
class StoreAiRunRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $entityId = $this->selectedEntityId();

        if ($entityId === '') {
            return false;
        }

        $gate = app(FeatureGateService::class);

        return $gate->can($entityId, FeatureKey::HasAiExamAssistant)
            || $gate->can($entityId, FeatureKey::HasAiReportDrafting)
            || $gate->can($entityId, FeatureKey::HasAiEyeImageAnalysis);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workflow'          => ['required', 'string', 'in:exam_assistant,report_drafting,consensus_review,eye_image_analysis,record_assist'],
            'mode'              => ['required', 'string', 'in:economy,validated,consensus'],
            'risk_level'        => ['required', 'string', 'in:low,medium,high'],
            'patient_id'        => ['nullable', 'uuid', 'exists:patients,id'],
            'medical_record_id' => ['nullable', 'uuid', 'exists:medical_records,id'],
            'user_prompt'       => ['required', 'string', 'min:12', 'max:30000'],
            'system_prompt'     => ['nullable', 'string', 'max:10000'],
            'context'           => ['nullable', 'array'],
            'attachments'       => ['nullable', 'array'],
            'exam_ids'          => ['nullable', 'array', 'max:' . (int) config('ai.eye_image.max_images', 4)],
            'exam_ids.*'        => ['uuid'],
            'expects_json'      => ['nullable', 'boolean'],
            'max_output_tokens' => ['nullable', 'integer', 'min:64', 'max:8192'],
        ];
    }

    public function selectedEntityId(): string
    {
        return (string) session('selected_entity_id');
    }
}
