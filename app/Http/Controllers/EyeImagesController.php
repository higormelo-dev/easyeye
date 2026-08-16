<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiCreditWalletService, AiProviderSettings, AiQuotaService};
use App\DTOs\EyeImageFilters;
use App\Enums\AI\{AiRunMode, AiRunStatus};
use App\Enums\FeatureKey;
use App\Models\{Doctor, Entity, EntityIntegratorEquipment, ExamType, Patient, PatientExam};
use App\Services\FeatureGateService;
use Closure;
use Illuminate\Database\Eloquent\{Builder, Collection};
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\{Inertia, Response as InertiaResponse};

class EyeImagesController extends Controller
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly AiProviderSettings $providerSettings,
        private readonly AiCreditWalletService $walletService,
        private readonly AiQuotaService $quotaService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId = session('selected_entity_id');
        $entityId = (string) $entityId;

        $doctors = Doctor::with('person')
            ->whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->get(['id', 'person_id']);

        $filters          = EyeImageFilters::fromRequest($request);
        $paginator        = $this->queryPatients($entityId, $filters);
        $entity           = Entity::find($entityId, ['id', 'name', 'address', 'telephone', 'cellphone', 'email', 'logo']);
        $hasExamAssistant = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $canEyeImage      = $this->featureGate->can($entityId, FeatureKey::HasAiEyeImageAnalysis);
        $canConsensus     = $this->providerSettings->isModeAvailable(AiRunMode::Consensus)
            && $this->featureGate->can($entityId, FeatureKey::HasAiConsensus);

        // Modos disponíveis escalam com o nº de provedores ativos (Gemini-only
        // ⇒ só Economia). Consenso ainda depende do recurso da entidade.
        $modes = [];

        foreach ($this->providerSettings->availableModes() as $mode) {
            if ($mode === AiRunMode::Consensus && ! $canConsensus) {
                continue;
            }
            $modes[] = ['value' => $mode->value, 'label' => __('ai.mode_' . $mode->value)];
        }

        return Inertia::render('Panel/EyeImages/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('dashboard.module_eye_images'), 'url' => '#', 'active' => true],
            ],
            'entity' => [
                'id'        => $entity?->id,
                'name'      => $entity?->name,
                'address'   => $entity?->address,
                'telephone' => $entity?->telephone,
                'cellphone' => $entity?->cellphone,
                'email'     => $entity?->email,
                'logo'      => $entity?->logo,
            ],
            'doctors' => $doctors->map(fn (Doctor $d) => [
                'id'   => (string) $d->id,
                'name' => $d->person?->full_name,
            ]),
            'patients'    => $this->serializePatients($paginator->getCollection()),
            'meta'        => $this->paginatorMeta($paginator),
            'total_exams' => $this->countFilteredExams($entityId, $filters),
            'filters'     => $filters->toArray(),
            'exam_types'  => $this->availableExamTypes($entityId),
            'equipments'  => $this->availableEquipments($entityId),
            'urls'        => [
                'search'       => route('panel.eye-images.search'),
                'patient_urls' => route('panel.eye-images.patient-urls', ['patient' => '__ID__']),
                'image_url'    => route('panel.eye-images.image-url', ['exam' => '__ID__']),
                // shape=select + placeholder __Q__: formato remoto consumido por
                // SearchSelect.vue (filtro de Diagnóstico da barra de filtros).
                'cid10_search' => route('panel.eye-images.cid10-search', ['shape' => 'select']) . '&q=__Q__',
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
                'quota'         => $this->quotaService->currentMonthSnapshot($entityId),
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
                    'image_url'  => route('panel.eye-images.image-url', ['exam' => '__ID__']),
                    // Onda 3 — endpoints inline
                    'my_prompts_index'   => route('panel.ai-runs.my-prompts.index'),
                    'my_prompts_store'   => route('panel.ai-runs.my-prompts.store'),
                    'my_prompts_destroy' => route('panel.ai-runs.my-prompts.destroy', ['aiPrompt' => '__ID__']),
                    'escalate'           => route('panel.ai-runs.escalate', ['aiRun' => '__ID__']),
                    'feedback'           => route('panel.ai-runs.feedback', ['aiRun' => '__ID__']),
                    // Formato "cru" (sem shape=select) — consumido pelo Cid10Picker no
                    // step de review do approve (diagnóstico do laudo de imagem ocular).
                    'cid10_search' => route('panel.eye-images.cid10-search'),
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
                    'raw_cost_usd'                => __('ai.raw_cost_usd'),
                    'estimate'                    => __('ai.estimate'),
                    'run'                         => __('ai.run'),
                    'credits_available'           => __('ai.credits_available'),
                    'credits_reserved'            => __('ai.credits_reserved'),
                    'run_created_waiting_review'  => __('ai.run_created_waiting_review'),
                    'estimate_failed'             => __('ai.estimate_failed'),
                    'estimate_network_error'      => __('ai.estimate_network_error'),
                    'run_create_failed'           => __('ai.run_create_failed'),
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
        $filters   = EyeImageFilters::fromRequest($request);
        $paginator = $this->queryPatients($entityId, $filters);

        return response()->json([
            'patients'    => $this->serializePatients($paginator->getCollection()),
            'meta'        => $this->paginatorMeta($paginator),
            'total_exams' => $this->countFilteredExams($entityId, $filters),
        ]);
    }

    public function patientExamUrls(Patient $patient): JsonResponse
    {
        $entityId = session('selected_entity_id');
        abort_unless($patient->entity_id === $entityId, 403);

        $urls = $patient->exams()
            ->whereNotNull('archive')
            ->get(['id', 'archive'])
            ->mapWithKeys(fn ($exam) => [
                $exam->id => Storage::disk('s3')->temporaryUrl($exam->archive, now()->addHours(2)),
            ]);

        return response()->json(['urls' => $urls]);
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

    // ─────────────────────────────────────────────────────────────────────────

    private function serializePatients(Collection $patients): array
    {
        return $patients->map(fn (Patient $p) => [
            'id'        => (string) $p->id,
            'code'      => $p->code,
            'full_name' => $p->person?->full_name,
            'person'    => ['full_name' => $p->person?->full_name],
            'exams'     => $p->exams->map(fn (PatientExam $e) => [
                'id'                             => (string) $e->id,
                'exam_id'                        => $e->exam_id !== null ? (string) $e->exam_id : null,
                'laterality'                     => (int) ($e->laterality ?? 0),
                'active'                         => (bool) $e->active,
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

        $approved = $exam->aiRuns->first(
            fn (AiRun $r) => $r->status === AiRunStatus::Approved,
        );
        $run = $approved ?? $exam->aiRuns->first();

        if (! $run) {
            return null;
        }

        $isApproved = $run->status === AiRunStatus::Approved;

        return [
            'run_id'   => (string) $run->id,
            'status'   => $run->status?->value,
            'approved' => $isApproved,
            'content'  => $isApproved ? (string) $run->final_output : null,
        ];
    }

    private function queryPatients(string $entityId, EyeImageFilters $f): LengthAwarePaginator
    {
        $examFilter = $this->examFilterClosure($f, $entityId);

        return $this->patientBaseQuery($entityId, $f)
            ->whereHas('exams', $examFilter)
            ->with([
                'person',
                'exams' => function (Builder|Relation $q) use ($examFilter): void {
                    $examFilter($q);
                    $q->with([
                        'examType',
                        'doctor.person',
                        'equipment',
                        'aiRuns' => fn ($r) => $r->orderByDesc('ai_runs.created_at'),
                    ])->orderByDesc('created_at');
                },
            ])
            ->orderByDesc('updated_at')
            ->paginate(perPage: $f->perPage, page: $f->page);
    }

    /**
     * Total de exames que batem no filtro (não só os da página atual) — usado
     * pro contador "N exames" do cabeçalho. Reaplica o mesmo $examFilter da
     * listagem, mas contando direto em patient_exams em vez de via patients.
     */
    private function countFilteredExams(string $entityId, EyeImageFilters $f): int
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
    private function patientBaseQuery(string $entityId, EyeImageFilters $f): Builder
    {
        $query = Patient::query()->where('entity_id', $entityId);

        if ($f->search !== '') {
            $lower = mb_strtolower($f->search, 'UTF-8');
            $query->where(fn (Builder $w) => $w
                ->whereHas('person', fn (Builder $p) => $p->whereRaw('LOWER(people.full_name) LIKE ?', ["%{$lower}%"]))
                ->orWhereRaw('LOWER(patients.code) LIKE ?', ["%{$lower}%"]));
        }

        return $query;
    }

    /**
     * Closure de filtro de exame, reaplicada em três lugares (whereHas de
     * pacientes, eager-load dos exames do paciente, e count de exames) pra
     * garantir que "pacientes retornados" e "exames listados por paciente"
     * concordem sempre com o mesmo critério.
     */
    private function examFilterClosure(EyeImageFilters $f, string $entityId): Closure
    {
        $from = $this->periodStart($f->period);

        return function (Builder|Relation $q) use ($f, $entityId, $from): void {
            $q->where('created_at', '>=', $from);

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

            if ($f->status === 'laudado') {
                // Fonte de verdade de "laudado" é o AiRun aprovado — o mesmo sinal
                // que já alimenta o badge "Laudado (IA)" (examAiReport/ai_report.approved).
                // diagnosis_cids é metadado opcional do laudo, pode ficar vazio mesmo
                // com laudo aprovado, então não é usado aqui como critério de status.
                $q->whereHas('aiRuns', fn (Builder $r) => $r
                    ->where('ai_run_patient_exam.entity_id', $entityId)
                    ->where('ai_runs.status', AiRunStatus::Approved->value));
            }
        };
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
