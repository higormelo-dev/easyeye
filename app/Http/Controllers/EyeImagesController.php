<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiCreditWalletService, AiProviderSettings, AiQuotaService};
use App\Enums\AI\{AiRunMode, AiRunStatus};
use App\Enums\FeatureKey;
use App\Models\{Doctor, Entity, Patient, PatientExam};
use App\Services\FeatureGateService;
use Illuminate\Database\Eloquent\{Builder, Collection};
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\{JsonResponse, Request};
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

        $patients         = $this->queryPatients(entityId: $entityId, period: 'hoje');
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
            'patients' => $this->serializePatients($patients),
            'urls'     => [
                'search'       => route('panel.eye-images.search'),
                'patient_urls' => route('panel.eye-images.patient-urls', ['patient' => '__ID__']),
                'image_url'    => route('panel.eye-images.image-url', ['exam' => '__ID__']),
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
            't' => trans('dashboard'),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $entityId = session('selected_entity_id');

        $patients = $this->queryPatients(
            entityId: (string) $entityId,
            period:   $request->input('period', 'hoje'),
            doctorId: $request->input('doctor_id'),
        );

        return response()->json([
            'patients'    => $this->serializePatients($patients),
            'total_exams' => $patients->sum(fn ($p) => $p->exams->count()),
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

    private function queryPatients(
        string $entityId,
        string $period = 'hoje',
        ?string $doctorId = null,
    ): Collection {
        $from = $period === 'hoje'
            ? now()->startOfDay()
            : now()->subDays(max((int) $period, 1))->startOfDay();

        $examFilter = function (Builder|Relation $q) use ($from, $doctorId): void {
            $q->where('created_at', '>=', $from);

            if ($doctorId) {
                $q->where('doctor_id', $doctorId);
            }
        };

        return Patient::query()
            ->where('entity_id', $entityId)
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
            ->limit(200)
            ->get();
    }
}
