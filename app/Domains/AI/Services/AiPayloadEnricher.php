<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, EntityGate, FeatureKey};
use App\Models\{Entity, MedicalRecord, Patient, PatientExam};
use App\Services\FeatureGateService;
use Illuminate\Support\Facades\Gate;

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
    /**
     * Campos clínicos do prontuário que a IA pode sugerir (record_assist).
     * Espelha as chaves do JSON em ai.record_assist_system_prompt e os v-model
     * do MedicalRecordForm (1:1, exceto diagnosis_hypothesis → conduta/observação).
     *
     * @var list<string>
     */
    private const RECORD_FIELDS = [
        'main_complaint', 'hda', 'medications_in_use', 'ocular_surgical_history',
        'others_history', 'ocular_motility', 'biomicroscopy_right', 'biomicroscopy_left',
        'fundoscopy_right', 'fundoscopy_left', 'gonioscopy_right', 'gonioscopy_left',
        'observation_of_lenses', 'clinical_conduct', 'observation_general', 'diagnosis_hypothesis',
    ];

    /**
     * Workflows de chat livre multi-turno (têm histórico de conversa via
     * conversation_id — ver buildConversationHistory()). assistant_chat é o
     * assistente clínico do médico; platform_finance_chat é o "converse com
     * os dados" do P&L interno do SaaS — mesma mecânica, contextos disjuntos.
     *
     * @var list<string>
     */
    private const CHAT_WORKFLOWS = ['assistant_chat', 'platform_finance_chat'];

    /**
     * Workflows do P&L interno do EasyEye — NUNCA gated por FeatureKey de
     * plano de clínica (não é benefício vendável a clientes) e SEMPRE gated
     * pelo Gate SaasOwnerFinancial (dono/admin do SaaS), não por qualquer
     * ClientRule de clínica. Ver EntityGate::SaasOwnerFinancial.
     *
     * @var list<string>
     */
    private const PLATFORM_FINANCE_WORKFLOWS = ['platform_finance_digest', 'platform_finance_chat'];

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

        $payload['context'] = $this->buildContext($payload, $entityId);

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

        // SEGURANÇA: system_prompt é SEMPRE decidido pelo servidor, nunca pelo
        // cliente. StoreAiRunRequest aceita um campo `system_prompt` no
        // payload (histórico: pensado para uso futuro/depuração) — sem este
        // descarte, um POST direto à API (fora da UI, que nunca envia esse
        // campo) conseguia substituir integralmente as instruções enviadas
        // ao provedor de IA para QUALQUER workflow, incluindo os que não
        // tinham prompt forçado abaixo (prompt injection via API). O valor
        // definitivo é atribuído em cada branch logo em seguida.
        unset($payload['system_prompt']);

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

            // Modo por campo: quando `field` é um campo clínico suportado, usa o
            // prompt single-field (sugere só aquele campo). Senão, análise completa.
            $field = (string) ($payload['field'] ?? '');

            $payload['system_prompt'] = $field !== '' && in_array($field, self::RECORD_FIELDS, true)
                ? __('ai.record_assist_field_system_prompt', ['field' => __('ai.record_fields.' . $field), 'key' => $field])
                : __('ai.record_assist_system_prompt');

            $payload['expects_json'] = true;
        }

        // Assistente virtual flutuante (chat livre, qualquer tela do painel).
        // Diferente dos outros workflows: sem medical_record_id obrigatório,
        // sem expects_json (resposta é texto livre), e com histórico de
        // conversa multi-turno via conversation_id (ver buildConversationHistory).
        if ($workflow === 'assistant_chat') {
            $payload['system_prompt'] = __('ai.assistant_chat_system_prompt');
            $payload['expects_json']  = false;
        }

        // P&L interno do EasyEye — digest estruturado (ganhando/perdendo/
        // oportunidades/ações, cada item com o dado que fundamenta a
        // conclusão). O `context` (resumo financeiro do período) já vem
        // pronto do controller — o enricher só fixa o system prompt e o
        // formato de saída; ver Manager\FinanceController.
        if ($workflow === 'platform_finance_digest') {
            $payload['system_prompt'] = __('ai.platform_finance_digest_system_prompt');
            $payload['expects_json']  = true;
        }

        // "Converse com os dados" do P&L interno — chat livre multi-turno,
        // mesmo padrão do assistant_chat, mas contexto e prompt exclusivos
        // do financeiro da plataforma (nunca dado clínico/paciente).
        if ($workflow === 'platform_finance_chat') {
            $payload['system_prompt'] = __('ai.platform_finance_chat_system_prompt');
            $payload['expects_json']  = false;
        }

        // Estes três workflows não tinham prompt forçado (só validados por
        // FeatureGate) — na prática rodavam SEM system prompt algum (a UI
        // real nunca envia `system_prompt`), e um POST direto à API podia
        // preencher o campo livremente. Prompt clínico dedicado adicionado.
        if (in_array($workflow, ['exam_assistant', 'report_drafting', 'consensus_review'], true)) {
            $payload['system_prompt'] = __('ai.' . $workflow . '_system_prompt');
        }

        if (isset($payload['system_prompt'])) {
            $payload['system_prompt'] = $this->withSecurityPreamble((string) $payload['system_prompt']);
        }

        return $payload;
    }

    /**
     * Prependa o preâmbulo de segurança (regras de precedência de
     * instrução + como tratar o bloco <clinic_data>) a todo system prompt.
     * Ponto ÚNICO — nenhum workflow monta o prompt final sem passar por
     * aqui, incluindo os anteriores a esta mudança.
     */
    private function withSecurityPreamble(string $workflowPrompt): string
    {
        return __('ai.security_preamble') . $workflowPrompt;
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

        if ($workflow === 'assistant_chat') {
            if (! $this->featureGate->can($entityId, FeatureKey::HasAiChatAssistant)) {
                abort(403, __('ai.feature_chat_unavailable'));
            }

            // Defesa em profundidade: approve() já é doctor-only (Gate
            // IssueReport), mas store() sozinho não era — sem este check,
            // secretária/admin/financeiro conseguiam criar runs de chat que
            // reservam crédito e nunca são aprovados (ninguém com permissão
            // pra aprovar), vazando reserva até expirar. Bloqueia na origem.
            if (session('selected_entity_user_rule') !== ClientRule::Doctor->value) {
                abort(403, __('ai.feature_chat_unavailable'));
            }
        }

        // P&L interno: nunca FeatureKey (não é plano vendável a clínica) —
        // exclusivamente Gate::SaasOwnerFinancial (admin OU dono do SaaS na
        // própria entity SaaS). `$entityId` aqui já é a entity SaaS (o
        // controller resolve via session('selected_entity_id') com o painel
        // manager selecionado nela) — se um usuário de CLÍNICA de algum jeito
        // acionar este workflow, `$entity->isSaas()` é false dentro do Gate
        // e o authorize() já barra, mas o check explícito documenta a intenção.
        if (in_array($workflow, self::PLATFORM_FINANCE_WORKFLOWS, true)) {
            $entity = Entity::findOrFail($entityId);

            if (! Gate::forUser(auth()->user())->allows(EntityGate::SaasOwnerFinancial->value, $entity)) {
                abort(403, __('ai.feature_platform_finance_unavailable'));
            }
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
    private function buildContext(array $payload, string $entityId): array
    {
        $userContext = (array) ($payload['context'] ?? []);

        $patient = ! empty($payload['patient_id'])
            ? Patient::query()->find((string) $payload['patient_id'])
            : null;

        $record = ! empty($payload['medical_record_id'])
            ? MedicalRecord::query()->find((string) $payload['medical_record_id'])
            : null;

        $context = $userContext;

        if ($patient || $record) {
            $serverContext = $this->contextBuilder->build($patient, $record);

            // Server context tem prioridade (anonimização + minimização).
            $context = array_merge($userContext, $serverContext, [
                '_built_by' => 'AiMedicalContextBuilder',
            ]);
        }

        $workflow = (string) ($payload['workflow'] ?? '');

        if (in_array($workflow, self::CHAT_WORKFLOWS, true) && ! empty($payload['conversation_id'])) {
            $history = $this->buildConversationHistory((string) $payload['conversation_id'], $entityId, $workflow);

            if ($history !== []) {
                $context['conversation_history'] = $history;
            }
        }

        return $context;
    }

    /**
     * Últimos turnos da mesma conversa (assistant_chat OU platform_finance_chat)
     * para dar memória multi-turno ao assistente. Escopo estrito: mesma
     * entity + mesmo solicitante + mesmo workflow — nunca mistura o histórico
     * do chat clínico com o do chat financeiro (mesmo se algum dia colidissem
     * conversation_id por acaso), e nunca vaza conversa de outro
     * médico/admin pelo conversation_id (UUID gerado no cliente, sem
     * controle de posse próprio).
     *
     * @return list<array{role: string, content: string}>
     */
    private function buildConversationHistory(string $conversationId, string $entityId, string $workflow): array
    {
        $runs = AiRun::query()
            ->where('entity_id', $entityId)
            ->where('conversation_id', $conversationId)
            ->where('requested_by', (string) auth()->id())
            ->where('workflow', $workflow)
            ->whereIn('status', [AiRunStatus::Approved->value, AiRunStatus::WaitingApproval->value])
            // `id` como desempate: mensagens do chat podem cair no mesmo
            // segundo de created_at (respostas rápidas em sequência) — HasUuids
            // gera UUID ordenado (v7-like), então id desc é cronologicamente
            // estável mesmo quando created_at colide.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['input_summary', 'final_output'])
            ->reverse();

        $history = [];

        foreach ($runs as $run) {
            $userPrompt = (string) ($run->input_summary['user_prompt'] ?? '');

            if ($userPrompt !== '') {
                $history[] = ['role' => 'user', 'content' => $userPrompt];
            }

            if (! empty($run->final_output)) {
                $history[] = ['role' => 'assistant', 'content' => (string) $run->final_output];
            }
        }

        return $history;
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
