<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiCreditWalletService, AiPaywallService, AiProviderSettings, AiQuotaService, EyeImageAttachmentService, SpeechTranscriptionService};
use App\DTOs\ExamManagerFilters;
use App\Enums\AI\{AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DraftStatus, EntityGate, FeatureKey, Permission};
use App\Models\{Doctor, Entity, EntityIntegratorEquipment, ExamType, EyeImageReportDraft, Patient, PatientDocumentShare, PatientExam};
use App\Services\EyeImages\BoundedVisionProcess;
use App\Services\FeatureGateService;
use App\Support\{BrazilianFormat, CurrentDoctor};
use App\Support\Documents\DocumentDate;
use App\Traits\LogsDataAccess;
use Closure;
use Illuminate\Database\Eloquent\{Builder, Collection};
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Gate, Storage};
use Inertia\{Inertia, Response as InertiaResponse};

class ExamManagerController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly AiProviderSettings $providerSettings,
        private readonly AiCreditWalletService $walletService,
        private readonly AiQuotaService $quotaService,
        private readonly AiPaywallService $paywall,
        private readonly SpeechTranscriptionService $speechTranscription,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId = session('selected_entity_id');
        $entityId = (string) $entityId;

        $doctors = Doctor::with('person')
            ->whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->get(['id', 'person_id']);

        $filters   = ExamManagerFilters::fromRequest($request);
        $paginator = $this->queryPatients($entityId, $filters);
        // is_client precisa vir na seleção: os Gates (DraftImageReport etc.) começam
        // por Entity::isClient(), que lê esta coluna — sem ela o Gate negava sempre e
        // o botão/selo de Rascunhos nunca aparecia para ninguém.
        $entity           = Entity::find($entityId, ['id', 'name', 'address', 'telephone', 'cellphone', 'email', 'logo', 'is_client']);
        $hasExamAssistant = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $canEyeImage      = $this->featureGate->can($entityId, FeatureKey::HasAiEyeImageAnalysis);
        $canConsensus     = $this->providerSettings->isModeAvailable(AiRunMode::Consensus)
            && $this->featureGate->can($entityId, FeatureKey::HasAiConsensus);
        // Panorama/Auto-alinhar rodam Python+OpenCV em processo isolado: sem o
        // isolamento do SO a UI nem os oferece (a disponibilidade do cv2 em si
        // é tratada pelo 422 do endpoint, sem checagem por request).
        $vision = app(BoundedVisionProcess::class)->isAvailable();

        // Rascunho de laudo (Fase 5 — secretária-rascunha/médico-emite):
        // contagem só quando o papel atual pode rascunhar, pra não gastar
        // query em perfis sem acesso (ex.: financeiro, que também cai neste
        // index() sem entity.role).
        $canDraftReports = (bool) ($entity && Gate::allows(EntityGate::DraftImageReport->value, $entity));
        $pendingDrafts   = $canDraftReports
            ? EyeImageReportDraft::query()->where('entity_id', $entityId)->where('status', DraftStatus::Submitted)->count()
            : 0;

        // Devolvidos pelo médico ao técnico (Fase 5): só para quem prepara —
        // o selo é "o que eu preciso ajustar", então conta o que ELE preparou.
        $returnedDrafts = $canDraftReports && ! CurrentDoctor::canIssue()
            ? EyeImageReportDraft::query()
                ->where('entity_id', $entityId)
                ->where('status', DraftStatus::Returned)
                ->where('created_by', $request->user()?->getKey())
                ->count()
            : 0;

        // Modos disponíveis escalam com o nº de provedores ativos (Gemini-only
        // ⇒ só Economia). Consenso ainda depende do recurso da entidade.
        $modes = [];

        foreach ($this->providerSettings->availableModes() as $mode) {
            if ($mode === AiRunMode::Consensus && ! $canConsensus) {
                continue;
            }
            $modes[] = ['value' => $mode->value, 'label' => __('ai.mode_' . $mode->value)];
        }

        return Inertia::render('Panel/ExamManager/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('dashboard.module_eye_images'), 'url' => '#', 'active' => true],
            ],
            // Telefones só para exibição (cabeçalho de impressão) — banco guarda só dígitos.
            'entity' => [
                'id'        => $entity?->id,
                'name'      => $entity?->name,
                'address'   => $entity?->address,
                'telephone' => BrazilianFormat::phone($entity?->telephone),
                'cellphone' => BrazilianFormat::phone($entity?->cellphone),
                'email'     => $entity?->email,
                'logo'      => $entity?->logo,
            ],
            'doctors' => $doctors->map(fn (Doctor $d) => [
                'id'   => (string) $d->id,
                'name' => $d->person?->full_name,
            ]),
            'patients'     => $this->serializePatients($paginator->getCollection()),
            'reviewTarget' => $this->reviewTarget($request, $entityId),
            'meta'         => $this->paginatorMeta($paginator),
            'total_exams'  => $this->countFilteredExams($entityId, $filters),
            'filters'      => $filters->toArray(),
            'exam_types'   => $this->availableExamTypes($entityId),
            'equipments'   => $this->availableEquipments($entityId),
            // Laudo manual e diagnóstico de exame são atos clínicos exclusivos
            // do médico (CFM Res. 2.227/2018 — mesmo Gate IssueReport checado
            // no ExamManagerReportController). A UI usa isso pra esconder os
            // botões; o backend valida de novo, não depende só disto.
            // Perfil médico SEM cadastro de médico ativo: o Gate barra — a tela
            // esconde as ações e mostra o aviso (App\Support\CurrentDoctor).
            'isDoctor'          => CurrentDoctor::canIssue(),
            'doctorMissing'     => CurrentDoctor::issuerMissing(),
            'doctorMissingText' => CurrentDoctor::missingMessage(),
            // Histórico do paciente (somente leitura): espelha o middleware da rota
            // exam-manager.patients.audit (mesmo grupo de Pacientes).
            'can_view_patient_audit' => $this->canViewPatientAudit($request, $entity),
            // Data alternativa do laudo manual (Fase 5) — mesma faixa/config
            // já usada em MedicalRecordsController (documents.date).
            'document_date_config' => DocumentDate::clientConfig(),
            // Rascunho de laudo (Fase 5) — ver ExamManagerReportDraftController.
            'canDraftReports'          => $canDraftReports,
            'draftReportsPendingCount' => $pendingDrafts,
            // Ditado por voz do laudo (Fase 5): só aparece com o recurso ligado E o
            // provedor dentro da política LGPD (SpeechTranscriptionService::resolve()).
            'dictation' => [
                'available'   => $canDraftReports && $this->speechTranscription->isAvailable(),
                'max_seconds' => (int) config('ai.transcription.max_seconds', 120),
            ],
            'draftReportsReturnedCount' => $returnedDrafts,
            // Captura pela câmera (Fase 5 / 6a) — auto-stop client-side da
            // gravação; o teto que de fato vale é max_video_kb, validado no
            // backend em ImportExternalExamRequest.
            'max_video_seconds' => (int) config('eye_image_capture.capture.max_video_seconds'),
            // Pedal USB (Fase 5 / 7a/7b) — allowlist + default; a tecla
            // efetivamente salva é sempre revalidada em PreferencesController
            // (ALLOWED_KEYS + Rule::in), isto aqui só monta o <select>.
            'pedal_shortcuts_config' => [
                'allowed_keys'        => config('eye_image_capture.pedal.allowed_keys'),
                'default_capture_key' => config('eye_image_capture.pedal.default_capture_key'),
                'default_advance_key' => config('eye_image_capture.pedal.default_advance_key'),
            ],
            'urls' => [
                'search'       => route('panel.exam-manager.search'),
                'patient_urls' => route('panel.exam-manager.patient-urls', ['patient' => '__ID__']),
                // Exames com metadados (tipo/olho) + URL — usado ao abrir um
                // rascunho de laudo (Fase 5): o paciente do rascunho pode não
                // ser o paciente selecionado na tela (sem exams/URLs já em
                // memória). Mesmo endpoint já usado pelo painel do prontuário.
                'patient_exams' => route('panel.exam-manager.patient-exams', ['patient' => '__ID__']),
                'image_url'     => route('panel.exam-manager.image-url', ['exam' => '__ID__']),
                // Histórico (auditoria) do paciente — ver ExamManagerPatientAuditController.
                'patient_audit' => route('panel.exam-manager.patients.audit', ['patient' => '__ID__']),
                // Visto/não visto — marcado ao exibir o exame no visualizador.
                'exam_viewed_update' => route('panel.exam-manager.exams.viewed', ['exam' => '__ID__']),
                // Portal do Paciente (Fase 2) — toggle "compartilhar com paciente".
                'document_share_store'   => route('panel.document-shares.store'),
                'document_share_destroy' => route('panel.document-shares.destroy', ['share' => '__ID__']),
                // Laudo manual (Modelos) — ver ExamManagerReportController.
                'report_templates'        => route('panel.exam-manager.report-templates.index'),
                'report_preview'          => route('panel.exam-manager.report-templates.preview'),
                'report_store'            => route('panel.exam-manager.reports.store'),
                'report_extract_pdf_text' => route('panel.exam-manager.reports.extract-pdf-text'),
                // Reenvio ao médico solicitante (Fase 5) — ver ExamManagerReportResendController.
                'report_resend' => route('panel.exam-manager.reports.resend', ['documentation' => '__ID__']),
                // Rascunho de laudo (Fase 5) — ver ExamManagerReportDraftController.
                'report_drafts_index'   => route('panel.exam-manager.report-drafts.index'),
                'report_drafts_store'   => route('panel.exam-manager.report-drafts.store'),
                'report_drafts_show'    => route('panel.exam-manager.report-drafts.show', ['draft' => '__ID__']),
                'report_drafts_update'  => route('panel.exam-manager.report-drafts.update', ['draft' => '__ID__']),
                'report_drafts_submit'  => route('panel.exam-manager.report-drafts.submit', ['draft' => '__ID__']),
                'report_drafts_return'  => route('panel.exam-manager.report-drafts.return', ['draft' => '__ID__']),
                'report_dictate'        => route('panel.exam-manager.reports.dictate'),
                'report_drafts_destroy' => route('panel.exam-manager.report-drafts.destroy', ['draft' => '__ID__']),
                // Frases rápidas do médico (benchmark 18/09/2026) — ver
                // DoctorReportPhrasesController.
                'report_phrases_index'   => route('panel.exam-manager.report-phrases.index'),
                'report_phrases_store'   => route('panel.exam-manager.report-phrases.store'),
                'report_phrases_update'  => route('panel.exam-manager.report-phrases.update', ['phrase' => '__ID__']),
                'report_phrases_destroy' => route('panel.exam-manager.report-phrases.destroy', ['phrase' => '__ID__']),
                // shape=select + placeholder __Q__: formato remoto consumido por
                // SearchSelect.vue (filtro de Diagnóstico da barra de filtros).
                'cid10_search' => route('panel.exam-manager.cid10-search', ['shape' => 'select']) . '&q=__Q__',
                // Diagnóstico do exame (CID-10 + customizados da clínica) — ver
                // ExamDiagnosisController.
                'diagnoses_search'      => route('panel.exam-manager.diagnoses.search'),
                'diagnoses_store'       => route('panel.exam-manager.diagnoses.store'),
                'exam_diagnosis_update' => route('panel.exam-manager.exams.diagnosis.update', ['exam' => '__ID__']),
                // Menu de contexto da miniatura — ver ExamManagerActionsController.
                'exam_laterality_update'     => route('panel.exam-manager.exams.laterality.update', ['exam' => '__ID__']),
                'exam_quality_rating_update' => route('panel.exam-manager.exams.quality-rating.update', ['exam' => '__ID__']),
                'exam_active_update'         => route('panel.exam-manager.exams.active.update', ['exam' => '__ID__']),
                'exam_urgent_update'         => route('panel.exam-manager.exams.urgent.update', ['exam' => '__ID__']),
                // Estrela de prioridade/triagem do PACIENTE (benchmark 18/09/2026) —
                // PatientsController::updatePriority, não é ação de exame.
                'patient_priority_update' => route('panel.patients.priority.update', ['patient' => '__ID__']),
                // Montage (benchmark 18/09/2026) — ver ExamManagerMontageController.
                'montage' => route('panel.exam-manager.montage.store'),
                // Panorama e Auto-alinhar (OpenCV em processo isolado): sem
                // isolamento no servidor a UI nem oferece (null).
                'panorama'      => $vision ? route('panel.exam-manager.panorama.store') : null,
                'align'         => $vision ? route('panel.exam-manager.align.store') : null,
                'exams_merge'   => route('panel.exam-manager.exams.merge'),
                'exams_split'   => route('panel.exam-manager.exams.split'),
                'exams_ungroup' => route('panel.exam-manager.exams.ungroup'),
                // Importar exame externo (upload manual, sem integrador) — ver
                // ExternalExamImportController.
                'import_store' => route('panel.exam-manager.import.store'),
            ],
            'ai' => [
                'enabled'          => $canEyeImage || $hasExamAssistant || $canConsensus,
                'can_eye_image'    => $canEyeImage,
                'default_workflow' => $canEyeImage ? 'eye_image_analysis' : 'exam_assistant',
                'workflows'        => array_values(array_filter([
                    $canEyeImage ? 'eye_image_analysis' : null,
                    $hasExamAssistant ? 'exam_assistant' : null,
                    $canConsensus ? 'consensus_review' : null,
                ])),
                'can_consensus' => $canConsensus,
                'modes'         => $modes,
                'balance'       => $this->walletService->balance($entityId),
                'quota'         => $this->quotaService->snapshot($entityId),
                'paywall'       => $this->paywall->describe($entityId),
                'max_images'    => (int) config('ai.eye_image.max_images', 4),
                'urls'          => [
                    'estimate'   => route('panel.ai-runs.estimate'),
                    'store'      => route('panel.ai-runs.store'),
                    'show'       => route('panel.ai-runs.show', ['aiRun' => '__ID__']),
                    'approve'    => route('panel.ai-runs.approve', ['aiRun' => '__ID__']),
                    'reject'     => route('panel.ai-runs.reject', ['aiRun' => '__ID__']),
                    'cancel'     => route('panel.ai-runs.cancel', ['aiRun' => '__ID__']),
                    'record'     => route('panel.ai-runs.record', ['aiRun' => '__ID__']),
                    'by_patient' => route('panel.ai-runs.by-patient', ['patient' => '__ID__']),
                    'image_url'  => route('panel.exam-manager.image-url', ['exam' => '__ID__']),
                    // Onda 3 — endpoints inline
                    'my_prompts_index'   => route('panel.ai-runs.my-prompts.index'),
                    'my_prompts_store'   => route('panel.ai-runs.my-prompts.store'),
                    'my_prompts_destroy' => route('panel.ai-runs.my-prompts.destroy', ['aiPrompt' => '__ID__']),
                    'escalate'           => route('panel.ai-runs.escalate', ['aiRun' => '__ID__']),
                    'feedback'           => route('panel.ai-runs.feedback', ['aiRun' => '__ID__']),
                    // Formato "cru" (sem shape=select) — consumido pelo Cid10Picker no
                    // step de review do approve (diagnóstico do laudo de imagem ocular).
                    'cid10_search' => route('panel.exam-manager.cid10-search'),
                ],
                // Rótulos do painel compartilhado (AiAssistantPanel).
                'assistant'       => trans('ai.assistant'),
                'workflow_labels' => [
                    'record_assist'      => __('ai.workflow_record_assist'),
                    'report_drafting'    => __('ai.workflow_report_drafting'),
                    'eye_image_analysis' => __('ai.workflow_eye_image_analysis'),
                ],
                'labels' => [
                    'workflow_eye_image_analysis' => __('ai.workflow_eye_image_analysis'),
                    'eye_image_analyze'           => __('eye_images.ai_analyze'),
                    'eye_image_selected'          => __('eye_images.ai_selected_images'),
                    'eye_image_none'              => __('eye_images.ai_no_selection'),
                    'eye_image_report'            => __('eye_images.ai_report'),
                    'eye_image_reported'          => __('eye_images.ai_reported_badge'),
                    'approve'                     => __('ai.approve'),
                    'reject'                      => __('ai.reject'),
                    'processing'                  => __('ai.processing'),
                    'record_confirm_open'         => __('ai.record_confirm_open'),
                    'record_opened'               => __('ai.record_opened'),
                    'mode'                        => __('ai.mode'),
                    'title'                       => __('ai.title'),
                    'assistance_button'           => __('ai.assistance_button'),
                    'support_notice'              => __('ai.support_notice'),
                    'workflow'                    => __('ai.workflow'),
                    'risk'                        => __('ai.risk'),
                    'patient_optional'            => __('ai.patient_optional'),
                    'select_placeholder'          => __('ai.select_placeholder'),
                    'system_prompt'               => __('ai.system_prompt'),
                    'system_prompt_default'       => __('ai.system_prompt_default'),
                    'clinical_prompt'             => __('ai.clinical_prompt'),
                    'clinical_prompt_placeholder' => __('ai.clinical_prompt_placeholder'),
                    'prompt_min_chars'            => __('ai.prompt_min_chars', ['min' => 12]),
                    'estimated_credits'           => __('ai.estimated_credits'),
                    'estimate'                    => __('ai.estimate'),
                    'run'                         => __('ai.run'),
                    'credits_available'           => __('ai.credits_available'),
                    'credits_reserved'            => __('ai.credits_reserved'),
                    'run_created_waiting_review'  => __('ai.run_created_waiting_review'),
                    'estimate_failed'             => __('ai.estimate_failed'),
                    'estimate_network_error'      => __('ai.estimate_network_error'),
                    'run_create_failed'           => __('ai.run_create_failed'),
                    'run_needs_images'            => __('eye_images.ai_run_needs_images'),
                    'run_network_error'           => __('ai.run_network_error'),
                    'workflow_exam_assistant'     => __('ai.workflow_exam_assistant'),
                    'workflow_consensus_review'   => __('ai.workflow_consensus_review'),
                    'risk_low'                    => __('ai.risk_low'),
                    'risk_medium'                 => __('ai.risk_medium'),
                    'risk_high'                   => __('ai.risk_high'),
                    'close'                       => __('actions.close'),
                ],
            ],
            // Era trans('dashboard') — chaves certas (search_placeholder, all_statuses,
            // status_reported etc.) sempre estiveram em eye_images.php e caíam no
            // fallback PT hardcoded do template. Ver lang/{pt_BR,en}/eye_images.php.
            't' => trans('eye_images'),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $entityId  = (string) session('selected_entity_id');
        $filters   = ExamManagerFilters::fromRequest($request);
        $paginator = $this->queryPatients($entityId, $filters);

        return response()->json([
            'patients'    => $this->serializePatients($paginator->getCollection()),
            'meta'        => $this->paginatorMeta($paginator),
            'total_exams' => $this->countFilteredExams($entityId, $filters),
        ]);
    }

    public function patientExamUrls(Patient $patient): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        abort_unless($entityId !== '' && (string) $patient->entity_id === $entityId, 403);

        $exams = $patient->exams()
            ->whereNotNull('archive')
            ->get(['id', 'archive', 'display_archive', 'thumb_archive']);

        $temporary = fn (?string $path) => $path
            ? Storage::disk('s3')->temporaryUrl($path, now()->addHours(2))
            : null;

        // `urls` (viewer): JPEG de exibição quando o derivado existe (inclusive
        // a 1ª página de laudos PDF), senão o original — compatível com o
        // front atual. `thumb_urls` (grid): miniatura com o mesmo fallback.
        // `original_urls`: sempre o arquivo original (baixar laudo PDF etc.).
        $urls         = [];
        $thumbUrls    = [];
        $originalUrls = [];

        foreach ($exams as $exam) {
            $original = $temporary($exam->archive);
            $display  = $temporary($exam->display_archive);

            $urls[$exam->id]         = $display ?? $original;
            $thumbUrls[$exam->id]    = $temporary($exam->thumb_archive) ?? $display ?? $original;
            $originalUrls[$exam->id] = $original;
        }

        return response()->json([
            'urls'          => $urls,
            'thumb_urls'    => $thumbUrls,
            'original_urls' => $originalUrls,
        ]);
    }

    /**
     * Exames de imagem do paciente COM metadados + URLs — consumido pelo
     * painel "Exames de imagem" do PRONTUÁRIO (o patientExamUrls acima serve
     * o viewer do módulo e devolve só os mapas de URLs). Registra o acesso
     * de leitura (LGPD art. 37 / CFM): quem consultou as imagens de quem.
     */
    public function patientExamsForRecord(Patient $patient): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        abort_unless($entityId !== '' && (string) $patient->entity_id === $entityId, 403);

        $this->logAccess($patient, patientId: (string) $patient->id);

        $exams = $patient->exams()
            ->whereNotNull('archive')
            ->with(['examType:id,name', 'doctor.person:id,full_name'])
            ->orderByDesc('exam_performed_at')
            ->orderByDesc('created_at')
            ->get();

        $temporary = fn (?string $path, int $hours = 2) => $path
            ? Storage::disk('s3')->temporaryUrl($path, now()->addHours($hours))
            : null;

        $items = $exams->map(function (PatientExam $exam) use ($temporary): array {
            $original = $temporary($exam->archive);
            $display  = $temporary($exam->display_archive);

            return [
                'id'           => (string) $exam->id,
                'exam_type'    => $exam->examType?->name,
                'performed_at' => ($exam->exam_performed_at ?? $exam->created_at)?->format('d/m/Y'),
                'laterality'   => [1 => 'OD', 2 => 'OE'][$exam->laterality] ?? 'AO',
                // Exame desabilitado (active=false) nunca deve compor um laudo
                // novo/rascunho reaberto (Fase 5 / revisão Codex) — sem este
                // campo, o rascunho não tinha como saber e emitir travava sem
                // explicação nem forma de corrigir.
                'active'    => $exam->active,
                'source'    => $exam->is_external ? ($exam->external_origin ?: 'Importado') : null,
                'diagnosis' => collect((array) $exam->diagnosis_cids)
                    ->pluck('code')->filter()->values()->all(),
                'is_pdf' => str_ends_with(mb_strtolower((string) $exam->archive), '.pdf'),
                // Vídeo capturado pela câmera (Fase 5 / 6a) tem archive/
                // display_url/original_url preenchidos como qualquer exame —
                // sem este campo, quem consome esta lista pra montar
                // candidato de IMAGEM (Inserir imagem/Imagens no laudo) não
                // tinha como saber que aquela URL é um vídeo, e acabava
                // tratando como foto (preview quebrado, pode até entrar no
                // corpo do laudo como <img>) — achado via revisão Codex.
                'is_video'     => (bool) preg_match('/\.(webm|mp4)$/i', (string) $exam->archive),
                'thumb_url'    => $temporary($exam->thumb_archive) ?? $display ?? $original,
                'display_url'  => $display ?? $original,
                'original_url' => $original,
            ];
        })->values()->all();

        return response()->json(['exams' => $items]);
    }

    public function imageUrl(PatientExam $exam): JsonResponse
    {
        abort_unless($exam->archive, 404);

        $entityId = session('selected_entity_id');
        abort_unless(
            Patient::where('id', $exam->patient_id)->where('entity_id', $entityId)->exists(),
            403,
        );

        return response()->json([
            'url' => Storage::disk('s3')->temporaryUrl($exam->archive, now()->addHour()),
        ]);
    }

    /**
     * Mesma regra do middleware `permission:patients.manage,admin,financial,doctor,secretary`
     * da rota do histórico — a tela só mostra o botão a quem a rota deixa passar.
     */
    private function canViewPatientAudit(Request $request, ?Entity $entity): bool
    {
        $user = $request->user();

        return $entity !== null && $user !== null
            && ($user->hasPermissionInEntity($entity, Permission::PatientsManage)
                || $user->hasAnyRoleInEntity($entity, [ClientRule::Admin, ClientRule::Financial, ClientRule::Doctor, ClientRule::Secretary]));
    }

    /**
     * Marca o exame como visto (primeira exibição no visualizador). UPDATE
     * atômico `whereNull`: idempotente (a 2ª chamada mantém o instante
     * original) e sem evento de model — não gera linha de auditoria nem mexe
     * em updated_at. Guarda QUANDO, não quem viu. Qualquer perfil que abre o
     * Gerenciador de exames marca; o tenant é checado pelo paciente do exame.
     */
    public function markExamViewed(PatientExam $exam): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        abort_unless($entityId !== '' && (string) $exam->patient?->entity_id === $entityId, 404);

        // toBase(): o update do Eloquent acrescentaria updated_at sozinho.
        PatientExam::query()->whereKey($exam->getKey())->whereNull('viewed_at')->toBase()->update(['viewed_at' => now()]);

        return response()->json([
            'id'        => (string) $exam->id,
            'viewed_at' => $exam->fresh()?->viewed_at?->toIso8601String(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function reviewTarget(Request $request, string $entityId): ?array
    {
        if (! $request->hasAny(['patient_id', 'exam_id'])) {
            return null;
        }
        $patientId = $request->query('patient_id');
        $examId    = $request->query('exam_id');
        abort_unless(is_string($patientId) && is_string($examId) && Str::isUuid($patientId) && Str::isUuid($examId), 404);
        $patient = Patient::where('entity_id', $entityId)->whereKey($patientId)
            ->whereHas('exams', fn ($q) => $q->whereKey($examId))
            ->with(['person', 'exams' => fn ($q) => $q->whereKey($examId)->with([
                'examType', 'doctor.person', 'equipment',
                'aiRuns' => fn ($r) => $r->orderByDesc('ai_runs.created_at')->with('documentation:id,ai_run_id,medical_record_id'),
            ])])->firstOrFail();

        return ['patient' => $this->serializePatients(new Collection([$patient]))[0], 'exam_id' => $examId];
    }

    private function serializePatients(Collection $patients): array
    {
        // Portal do Paciente (Fase 2) — grant ativo por exame, numa única
        // query em lote pra toda a página (não 1 por exame).
        $examIds            = $patients->flatMap(fn (Patient $p) => $p->exams->pluck('id'));
        $sharedExamShareIds = PatientDocumentShare::query()
            ->where('shareable_type', PatientExam::class)
            ->whereIn('shareable_id', $examIds)
            ->whereNull('revoked_at')
            ->pluck('id', 'shareable_id');

        // person.gender_label/age: barra do paciente no visualizador (mesmo
        // cabeçalho do PDF do laudo).
        return $patients->map(fn (Patient $p) => [
            'id'        => (string) $p->id,
            'code'      => $p->code,
            'full_name' => $p->person?->full_name,
            'person'    => [
                'full_name'    => $p->person?->full_name,
                'gender_label' => $p->person?->gender_label,
                'age'          => $p->person?->age,
            ],
            'priority_rating' => $p->priority_rating,
            'exams'           => $p->exams->map(fn (PatientExam $e) => [
                'id'             => (string) $e->id,
                'exam_id'        => $e->exam_id !== null ? (string) $e->exam_id : null,
                'laterality'     => (int) ($e->laterality ?? 0),
                'quality_rating' => $e->quality_rating !== null ? (int) $e->quality_rating : null,
                // Override do agrupamento visual (Mesclar/Dividir exame) — ver
                // migration 2026_09_09_110000 e Index.vue::groupedExams.
                'exam_session_id'                => $e->exam_session_id !== null ? (string) $e->exam_session_id : null,
                'active'                         => (bool) $e->active,
                'viewed_at'                      => $e->viewed_at?->toIso8601String(),
                'urgent'                         => (bool) $e->urgent,
                'archive'                        => $e->archive,
                'has_archive'                    => $e->archive !== null,
                'created_at'                     => optional($e->created_at)->toIso8601String(),
                'created_at_fmt'                 => $e->created_at?->format('d/m/Y H:i'),
                'entity_integrator_equipment_id' => $e->entity_integrator_equipment_id !== null
                    ? (string) $e->entity_integrator_equipment_id
                    : null,
                'exam_type' => $e->examType
                    ? ['id' => (string) $e->examType->id, 'name' => $e->examType->name]
                    : null,
                'exam_type_name' => $e->examType?->name,
                'doctor'         => $e->doctor && $e->doctor->person
                    ? ['id' => (string) $e->doctor->id, 'name' => $e->doctor->person->full_name]
                    : null,
                'doctor_name' => $e->doctor?->person?->full_name,
                'equipment'   => $e->equipment
                    ? ['id' => (string) $e->equipment->id, 'name' => $e->equipment->name]
                    : null,
                'equipment_name' => $e->equipment?->name,
                'diagnosis_cids' => $e->diagnosis_cids ?? [],
                'ai_report'      => $this->examAiReport($e),
                // Origem do exame (Importar exame externo) — ver App\Enums\ExamSource.
                'source'            => $e->source?->value,
                'is_external'       => $e->isExternal(),
                'external_origin'   => $e->external_origin,
                'exam_performed_at' => optional($e->exam_performed_at)->toIso8601String(),
                'observation'       => $e->observation,
                'import_batch_id'   => $e->import_batch_id,
                // Portal do Paciente (Fase 2) — grant de leitura pro titular.
                'shared_with_patient' => $sharedExamShareIds->has($e->id),
                'document_share_id'   => $sharedExamShareIds->get($e->id),
            ])->all(),
        ])->all();
    }

    /**
     * Laudo de IA do exame (run aprovado mais recente, ou último run em
     * processamento/aguardando). Retorna null quando não há análise.
     *
     * @return array<string, mixed>|null
     */
    private function examAiReport(PatientExam $exam): ?array
    {
        if (! $exam->relationLoaded('aiRuns') || $exam->aiRuns->isEmpty()) {
            return null;
        }

        // image_deidentification do pivot (ver withPivot() em
        // PatientExam::aiRuns()) — mesmo critério de
        // PatientExam::reportedConstraint(): só conta como "aprovado pra
        // ESTE exame" quando ele foi DE FATO analisado NESTA execução,
        // nunca só por o run estar Approved. Um vídeo num run misto
        // foto+vídeo nunca ganha outcome (GD não decodifica) — o run pode
        // estar Approved pela foto, e sem esta checagem a galeria rotulava
        // o vídeo como aprovado e abria o conteúdo/PDF da foto (achado via
        // revisão adversarial Codex).
        $wasAnalyzedIn = fn (AiRun $r) => $r->pivot->image_deidentification !== null
            && $r->pivot->image_deidentification !== EyeImageAttachmentService::SKIPPED_UNRECOGNIZED_LAYOUT;

        $approved = $exam->aiRuns->first(
            fn (AiRun $r) => $r->status === AiRunStatus::Approved && $wasAnalyzedIn($r),
        );
        $run = $approved ?? $exam->aiRuns->first();

        if (! $run) {
            return null;
        }

        $isApproved = $run->status === AiRunStatus::Approved && $wasAnalyzedIn($run);
        $doc        = $run->relationLoaded('documentation') ? $run->documentation : null;

        return [
            'run_id'   => (string) $run->id,
            'status'   => $run->status?->value,
            'approved' => $isApproved,
            'content'  => $isApproved ? (string) $run->final_output : null,
            // PDF formal do laudo (timbre/assinatura) — só existe quando o laudo
            // já foi gravado no prontuário (aprovado + prontuário do dia
            // resolvido; ver AiRunDocumentationService). Mesmo PDF do laudo
            // manual. Gated por $isApproved (não só $doc existir): o
            // documento do run misto existe de verdade, mas é o laudo da
            // FOTO — nunca o PDF certo pro exame que nunca foi analisado.
            'pdf_url' => ($isApproved && $doc && $doc->medical_record_id)
                ? route('panel.patients.medicalrecords.documentations.pdf', [$exam->patient_id, $doc->medical_record_id, $doc->id])
                : null,
        ];
    }

    private function queryPatients(string $entityId, ExamManagerFilters $f): LengthAwarePaginator
    {
        $examFilter = $this->examFilterClosure($f, $entityId);

        return $this->patientBaseQuery($entityId, $f)
            ->whereHas('exams', $examFilter)
            // Urgentes primeiro: paciente com exame urgente (dentro do filtro atual) sobe na lista.
            ->withCount(['exams as urgent_exams_count' => function (Builder|Relation $q) use ($examFilter): void {
                $examFilter($q);
                $q->where('urgent', true);
            }])
            ->with([
                'person',
                'exams' => function (Builder|Relation $q) use ($examFilter): void {
                    $examFilter($q);
                    $q->with([
                        'examType',
                        'doctor.person',
                        'equipment',
                        // .documentation: id do MedicalRecordDocumentation gravado na
                        // aprovação — monta o link de PDF do laudo (examAiReport()).
                        'aiRuns' => fn ($r) => $r->orderByDesc('ai_runs.created_at')->with('documentation:id,ai_run_id,medical_record_id'),
                    ])->orderByDesc('urgent')->orderByDesc('created_at');
                },
            ])
            ->orderByDesc('urgent_exams_count')
            ->orderByDesc('updated_at')
            ->paginate(perPage: $f->perPage, page: $f->page);
    }

    /**
     * Total de exames que batem no filtro (não só os da página atual) — usado
     * pro contador "N exames" do cabeçalho. Reaplica o mesmo $examFilter da
     * listagem, mas contando direto em patient_exams em vez de via patients.
     */
    private function countFilteredExams(string $entityId, ExamManagerFilters $f): int
    {
        $examFilter = $this->examFilterClosure($f, $entityId);
        $patientIds = $this->patientBaseQuery($entityId, $f)->select('patients.id');

        return PatientExam::query()
            ->whereIn('patient_id', $patientIds)
            ->where($examFilter)
            ->count();
    }

    /**
     * Base da query de pacientes: escopo por entity + busca por nome/código.
     * Compartilhada entre queryPatients() e countFilteredExams() pra não
     * duplicar a lógica de busca em dois lugares.
     */
    private function patientBaseQuery(string $entityId, ExamManagerFilters $f): Builder
    {
        $query = Patient::query()->where('entity_id', $entityId);

        if ($f->search !== '') {
            $query->where(fn (Builder $w) => $w
                ->whereHas('person', fn (Builder $p) => $p->whereLikeUnaccent('people.full_name', $f->search))
                ->orWhereLikeUnaccent('patients.code', $f->search));
        }

        return $query;
    }

    /**
     * Closure de filtro de exame, reaplicada em três lugares (whereHas de
     * pacientes, eager-load dos exames do paciente, e count de exames) pra
     * garantir que "pacientes retornados" e "exames listados por paciente"
     * concordem sempre com o mesmo critério.
     */
    private function examFilterClosure(ExamManagerFilters $f, string $entityId): Closure
    {
        [$from, $to] = $this->periodRange($f);

        return function (Builder|Relation $q) use ($f, $entityId, $from, $to): void {
            $q->where('created_at', '>=', $from);

            if ($to !== null) {
                $q->where('created_at', '<=', $to);
            }

            if ($f->doctorId) {
                $q->where('doctor_id', $f->doctorId);
            }

            if ($f->examTypeId) {
                $q->where('exam_id', $f->examTypeId);
            }

            if ($f->equipmentId) {
                $q->where('entity_integrator_equipment_id', $f->equipmentId);
            }

            if ($f->eye === 'od') {
                $q->where('laterality', 1);
            } elseif ($f->eye === 'oe') {
                $q->where('laterality', 2);
            } elseif ($f->eye === 'ao') {
                // NOT IN exclui NULL no SQL — o front trata laterality nula como AO
                // (Index.vue: `e.laterality ?? 0`), então o filtro precisa incluí-la.
                $q->where(fn (Builder $w) => $w->whereNull('laterality')->orWhereNotIn('laterality', [1, 2]));
            }

            if ($f->cidCode) {
                $q->whereJsonContains('diagnosis_cids', [['code' => $f->cidCode]]);
            }

            if ($f->customDiagnosisId) {
                $q->whereJsonContains('diagnosis_cids', [['custom_diagnosis_id' => $f->customDiagnosisId]]);
            }

            if ($f->source) {
                $q->where('source', $f->source);
            }

            // "Só urgentes" — marcação do médico (ExamManagerActionsController::updateUrgent).
            if ($f->urgent) {
                $q->where('urgent', true);
            }

            // Médico SOLICITANTE (quem pediu o laudo — texto livre no laudo salvo, não o
            // médico interno do exame): parcial, sem caixa e sem acento; só laudo vigente
            // da própria clínica (documentação excluída e rascunhos não contam).
            if ($f->requestingDoctor !== null) {
                $needle = addcslashes($f->requestingDoctor, '\\%_');

                $q->whereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('medical_record_documentation_exams as mrde')
                    ->join('medical_record_documentations as mrd', 'mrd.id', '=', 'mrde.medical_record_documentation_id')
                    ->whereColumn('mrde.patient_exam_id', 'patient_exams.id')
                    ->where('mrde.entity_id', $entityId)
                    ->whereNull('mrd.deleted_at')
                    ->whereLikeUnaccent('mrd.requesting_doctor_name', $needle));
            }

            // Visto/não visto (primeira exibição no visualizador) — ver markExamViewed().
            if ($f->viewed === 'vistos') {
                $q->whereNotNull('viewed_at');
            } elseif ($f->viewed === 'nao_vistos') {
                $q->whereNull('viewed_at');
            }

            // "Laudado" = laudo manual/conjunto vigente OU laudo de IA aprovado
            // (antes só a IA contava — laudo manual ficava como "sem laudo").
            // diagnosis_cids é metadado opcional do laudo, pode ficar vazio mesmo
            // com laudo, então não é critério de status. Ver PatientExam::scopeReported.
            if ($f->status === 'laudado') {
                $q->reported($entityId);
            } elseif ($f->status === 'pendente') {
                // Mesmo critério do indicador "Exames pendentes" do Dashboard.
                $q->pendingReport($entityId);
            }
        };
    }

    /**
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function periodRange(ExamManagerFilters $f): array
    {
        if ($f->period === 'custom' && $f->dateFrom !== null) {
            $from = Carbon::parse($f->dateFrom)->startOfDay();
            $to   = $f->dateTo !== null ? Carbon::parse($f->dateTo)->endOfDay() : now();

            // Garante from <= to mesmo se o usuário inverter os campos na UI.
            return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$this->periodStart($f->period), null];
    }

    private function periodStart(string $period): Carbon
    {
        return $period === 'hoje'
            ? now()->startOfDay()
            : now()->subDays(max((int) $period, 1))->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    private function paginatorMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'has_more'     => $paginator->hasMorePages(),
        ];
    }

    /**
     * Tipos de exame já usados pela entidade (todo o histórico, não só o
     * período filtrado) — lista estável que não encolhe conforme o filtro
     * de período muda, evitando a opção escolhida sumir do próprio select.
     *
     * @return array<int, array<string, string>>
     */
    private function availableExamTypes(string $entityId): array
    {
        return ExamType::query()
            ->whereIn('id', PatientExam::query()
                ->whereHas('patient', fn (Builder $q) => $q->where('entity_id', $entityId))
                ->select('exam_id')
                ->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ExamType $t) => ['id' => (string) $t->id, 'name' => $t->name])
            ->all();
    }

    /**
     * Equipamentos ativos da entidade, via integrador (entity_integrator_equipments
     * não tem entity_id direto — cadeia equipment.integrator.user.entity_id).
     *
     * @return array<int, array<string, string>>
     */
    private function availableEquipments(string $entityId): array
    {
        return EntityIntegratorEquipment::query()
            ->whereHas('integrator.user', fn (Builder $q) => $q->where('entity_id', $entityId))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (EntityIntegratorEquipment $e) => ['id' => (string) $e->id, 'name' => $e->name])
            ->all();
    }
}
