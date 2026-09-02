<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Fonte ÚNICA do system prompt enviado ao provedor de IA.
 *
 * Regra de segurança (prompt injection): o system prompt é SEMPRE definido
 * pelo servidor — nunca aceito do cliente, nunca reaproveitado "cru" de um
 * run antigo. Todo prompt sai daqui com o preâmbulo de segurança
 * (`ai.security_preamble`: hierarquia de instrução + tratamento dos blocos
 * <clinic_data>/<ai_draft> como dado, não comando).
 *
 * Três chamadores, um caminho:
 *   - AiPayloadEnricher (criação do run via UI/API)         → resolve()
 *   - AiRunsController::escalate (replay de run anterior)  → harden()
 *   - AiRunExecutionService (job, momento da chamada real) → harden()
 *
 * O harden() na execução é a última linha: mesmo um run criado por um
 * caminho antigo/esquecido (ou pendente na fila antes desta mudança) chega
 * ao provedor com o prompt endurecido.
 */
final class AiSystemPromptResolver
{
    /**
     * Campos clínicos do prontuário que o record_assist pode sugerir
     * individualmente (modo single-field). Espelha os v-model do
     * MedicalRecordForm e as chaves do JSON do prompt completo.
     *
     * @var list<string>
     */
    public const RECORD_FIELDS = [
        'main_complaint', 'hda', 'medications_in_use', 'ocular_surgical_history',
        'others_history', 'ocular_motility', 'biomicroscopy_right', 'biomicroscopy_left',
        'fundoscopy_right', 'fundoscopy_left', 'gonioscopy_right', 'gonioscopy_left',
        'observation_of_lenses', 'clinical_conduct', 'observation_general', 'diagnosis_hypothesis',
    ];

    /**
     * Workflows que, até 02/09/2026, NÃO tinham prompt forçado no servidor —
     * o valor gravado em input_summary desses runs pode ter vindo do cliente
     * (UI da tela Imagens Oftálmicas ou POST direto). Nunca reaproveitar:
     * sempre re-derivar.
     *
     * @var list<string>
     */
    private const NEVER_TRUST_STORED = ['exam_assistant', 'report_drafting', 'consensus_review'];

    /**
     * Prompt completo (preâmbulo + prompt clínico do workflow) para um run novo.
     */
    public function resolve(string $workflow, ?string $field = null): string
    {
        return $this->withPreamble($this->workflowPrompt($workflow, $field) ?? '');
    }

    /**
     * Prompt para reexecução (escalate / job) a partir do que foi gravado no
     * run. Reaproveita o gravado só quando ele é comprovadamente autoral do
     * servidor; caso contrário re-deriva. Idempotente (não duplica preâmbulo).
     */
    public function harden(string $workflow, ?string $stored, ?string $field = null): string
    {
        $stored = trim((string) $stored);

        if ($stored === '' || in_array($workflow, self::NEVER_TRUST_STORED, true)) {
            return $this->resolve($workflow, $field);
        }

        return $this->withPreamble($this->stripPreamble($stored));
    }

    public function isKnownRecordField(?string $field): bool
    {
        return $field !== null && $field !== '' && in_array($field, self::RECORD_FIELDS, true);
    }

    private function workflowPrompt(string $workflow, ?string $field): ?string
    {
        return match ($workflow) {
            'eye_image_analysis' => __('ai.eye_image_system_prompt'),
            'record_assist'      => $this->isKnownRecordField($field)
                ? __('ai.record_assist_field_system_prompt', ['field' => __('ai.record_fields.' . $field), 'key' => $field])
                : __('ai.record_assist_system_prompt'),
            'assistant_chat'          => __('ai.assistant_chat_system_prompt'),
            'platform_finance_digest' => __('ai.platform_finance_digest_system_prompt'),
            'platform_finance_chat'   => __('ai.platform_finance_chat_system_prompt'),
            'exam_assistant'          => __('ai.exam_assistant_system_prompt'),
            'report_drafting'         => __('ai.report_drafting_system_prompt'),
            'consensus_review'        => __('ai.consensus_review_system_prompt'),
            default                   => null,
        };
    }

    private function preamble(): string
    {
        return (string) __('ai.security_preamble');
    }

    private function withPreamble(string $prompt): string
    {
        return $this->preamble() . $prompt;
    }

    private function stripPreamble(string $prompt): string
    {
        $preamble = $this->preamble();

        return str_starts_with($prompt, $preamble)
            ? substr($prompt, strlen($preamble))
            : $prompt;
    }
}
