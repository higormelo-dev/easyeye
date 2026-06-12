<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AI\AiRunMode;
use App\Enums\FeatureKey;
use App\Models\{MedicalRecord, Patient, PatientExam};
use App\Services\FeatureGateService;

/**
 * Centraliza o enriquecimento + sanitização do payload de execução de IA.
 *
 * Responsabilidades:
 *  - autorizar exam_ids do fluxo de imagem ocular (cross-tenant guard)
 *  - forçar system_prompt server-side por workflow (eye_image/record_assist)
 *  - validar feature gates por workflow
 *  - validar ownership cross-tenant de patient_id / medical_record_id
 *  - construir contexto clínico minimizado via AiMedicalContextBuilder
 *  - aplicar guardrails do prompt (mascara PII, bloqueia jailbreak)
 *
 * Não persiste nada — devolve o payload pronto para o controller criar o AiRun.
 */
class AiPayloadEnricher
{
    public function __construct(
        private readonly AiMedicalContextBuilder $contextBuilder,
        private readonly AiPromptGuardrailService $promptGuardrails,
        private readonly FeatureGateService $featureGate,
        private readonly AiProviderSettings $providerSettings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function enrich(array $payload, string $entityId, bool $canConsensus): array
    {
        $payload = $this->applyWorkflowDefaults($payload, $entityId);
        $payload = $this->resolveModeAndAuthorize($payload, $canConsensus);

        $this->validateFeatureByWorkflow((string) $payload['workflow'], $entityId);
        $this->validateContextOwnership($payload, $entityId);

        $payload['context'] = $this->buildContext($payload);

        $guarded                           = $this->promptGuardrails->sanitizePayload($payload);
        $guarded['payload']['_guardrails'] = $guarded['guardrails'];

        return $guarded['payload'];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function applyWorkflowDefaults(array $payload, string $entityId): array
    {
        $workflow = (string) ($payload['workflow'] ?? '');

        if ($workflow === 'eye_image_analysis') {
            $payload['exam_ids']      = $this->authorizeExamIds((array) ($payload['exam_ids'] ?? []), $entityId);
            $payload['system_prompt'] = __('ai.eye_image_system_prompt');
            $payload['attachments']   = [];
            $payload['_image_count']  = count($payload['exam_ids']);
        }

        if ($workflow === 'record_assist') {
            if (empty($payload['medical_record_id'])) {
                abort(422, __('ai.record_assist_record_required'));
            }
            $payload['system_prompt'] = __('ai.record_assist_system_prompt');
            $payload['expects_json']  = true;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function resolveModeAndAuthorize(array $payload, bool $canConsensus): array
    {
        $usingConsensus = ($payload['mode'] ?? '') === AiRunMode::Consensus->value
            || ($payload['workflow'] ?? '') === 'consensus_review';

        if (($payload['workflow'] ?? '') === 'consensus_review') {
            $payload['mode'] = AiRunMode::Consensus->value;
            $usingConsensus  = true;
        }

        if ($usingConsensus && ! (bool) config('ai.enable_consensus', true)) {
            abort(422, __('ai.consensus_disabled'));
        }

        if ($usingConsensus && ! $canConsensus) {
            abort(403, __('ai.feature_consensus_unavailable'));
        }

        if (! in_array((string) $payload['mode'], $this->availableModes($canConsensus), true)) {
            abort(422, __('ai.mode_unavailable'));
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function availableModes(bool $canConsensus): array
    {
        $modes = [];

        foreach ($this->providerSettings->availableModes() as $mode) {
            if ($mode === AiRunMode::Consensus && ! $canConsensus) {
                continue;
            }
            $modes[] = $mode->value;
        }

        return $modes;
    }

    private function validateFeatureByWorkflow(string $workflow, string $entityId): void
    {
        if (in_array($workflow, ['exam_assistant', 'record_assist'], true)
            && ! $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant)) {
            abort(403, __('ai.feature_exam_unavailable'));
        }

        if ($workflow === 'eye_image_analysis' && ! $this->featureGate->can($entityId, FeatureKey::HasAiEyeImageAnalysis)) {
            abort(403, __('ai.feature_eye_image_unavailable'));
        }

        if (in_array($workflow, ['report_drafting', 'consensus_review'], true)
            && ! $this->featureGate->can($entityId, FeatureKey::HasAiReportDrafting)) {
            abort(403, __('ai.feature_report_unavailable'));
        }

        if ($workflow === 'consensus_review' && ! $this->featureGate->can($entityId, FeatureKey::HasAiConsensus)) {
            abort(403, __('ai.feature_consensus_unavailable'));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateContextOwnership(array $payload, string $entityId): void
    {
        if (! empty($payload['patient_id'])) {
            $patient = Patient::query()->findOrFail((string) $payload['patient_id']);
            abort_if((string) $patient->entity_id !== $entityId, 403);
        }

        if (! empty($payload['medical_record_id'])) {
            $record = MedicalRecord::query()->findOrFail((string) $payload['medical_record_id']);
            abort_if((string) $record->entity_id !== $entityId, 403);

            if (! empty($payload['patient_id'])) {
                abort_if((string) $record->patient_id !== (string) $payload['patient_id'], 422, __('ai.record_patient_mismatch'));
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function buildContext(array $payload): array
    {
        $userContext = (array) ($payload['context'] ?? []);

        $patient = ! empty($payload['patient_id'])
            ? Patient::query()->find((string) $payload['patient_id'])
            : null;

        $record = ! empty($payload['medical_record_id'])
            ? MedicalRecord::query()->find((string) $payload['medical_record_id'])
            : null;

        if (! $patient && ! $record) {
            return $userContext;
        }

        $serverContext = $this->contextBuilder->build($patient, $record);

        // Server context tem prioridade (anonimização + minimização).
        return array_merge($userContext, $serverContext, [
            '_built_by' => 'AiMedicalContextBuilder',
        ]);
    }

    /**
     * @param array<int, mixed> $examIds
     *
     * @return list<string>
     */
    private function authorizeExamIds(array $examIds, string $entityId): array
    {
        $examIds = array_values(array_unique(array_filter(array_map('strval', $examIds))));

        if ($examIds === []) {
            abort(422, __('ai.eye_image_exams_required'));
        }

        $owned = PatientExam::query()
            ->whereIn('patient_exams.id', $examIds)
            ->whereHas('patient', fn ($q) => $q->where('entity_id', $entityId))
            ->pluck('patient_exams.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        abort_if(count($owned) !== count($examIds), 403);

        return $owned;
    }
}
