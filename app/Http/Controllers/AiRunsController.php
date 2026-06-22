<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Exceptions\InsufficientAiCreditsException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiAnalyticsService, AiCreditPurchaseService, AiCreditWalletService, AiFeedbackService, AiPayloadEnricher, AiPricingService, AiProviderSettings, AiQuotaService, AiRunDocumentationService, AiRunEstimateService, AiRunExecutionService};
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, EntityGate, FeatureKey};
use App\Http\Requests\AI\{EstimateAiRunRequest, StoreAiRunRequest};
use App\Jobs\AI\RunAiWorkflowJob;
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, Subscription};
use App\Services\FeatureGateService;
use DomainException;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Gate};
use Inertia\{Inertia, Response as InertiaResponse};

class AiRunsController extends Controller
{
    public function __construct(
        private readonly AiCreditWalletService $walletService,
        private readonly AiPricingService $pricingService,
        private readonly FeatureGateService $featureGate,
        private readonly AiCreditPurchaseService $creditPurchaseService,
        private readonly AiProviderSettings $providerSettings,
        private readonly AiPayloadEnricher $enricher,
        private readonly AiQuotaService $quotaService,
        private readonly AiAnalyticsService $analyticsService,
        private readonly AiFeedbackService $feedbackService,
        private readonly AiRunDocumentationService $documentationService,
        private readonly AiRunEstimateService $estimateService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);
        $hasExamAssistant  = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $hasReportDrafting = $this->featureGate->can($entityId, FeatureKey::HasAiReportDrafting);
        $canConsensus      = $this->canConsensusForEntity($entityId);

        $statusFilter  = $request->string('status')->trim()->value();
        $allowedStatus = array_map(static fn (AiRunStatus $status) => $status->value, AiRunStatus::cases());

        $runsQuery = AiRun::query()
            ->where('entity_id', $entityId)
            ->with([
                'patient.person:id,full_name',
                'medicalRecord:id,code,patient_id',
                'requestedBy:id,name',
                'approvedBy:id,name',
            ])
            ->withCount('providerCalls')
            ->orderByDesc('created_at');

        if ($statusFilter !== '' && in_array($statusFilter, $allowedStatus, true)) {
            $runsQuery->where('status', $statusFilter);
        }

        // Pré-preenchimento opcional: quando o widget do prontuário linkar para o
        // painel com ?medical_record_id=..., a UI deve abrir o formulário já apontando
        // para o prontuário (validamos que pertence à entity ativa).
        $prefillRecordId = $request->string('medical_record_id')->trim()->value();
        $prefill         = [];

        if ($prefillRecordId !== '') {
            $record = MedicalRecord::query()
                ->where('id', $prefillRecordId)
                ->where('entity_id', $entityId)
                ->first();

            if ($record) {
                $prefill = [
                    'medical_record_id' => (string) $record->id,
                    'patient_id'        => (string) $record->patient_id,
                ];
            }
        }

        $runs                  = $runsQuery->paginate(12)->withQueryString();
        $balance               = $this->walletService->balance($entityId);
        $modes                 = $this->availableModes($canConsensus);
        $configuredDefaultMode = (string) config('ai.default_mode', AiRunMode::Validated->value);
        $defaultMode           = in_array($configuredDefaultMode, $modes, true)
            ? $configuredDefaultMode
            : ($modes[0] ?? AiRunMode::Economy->value);
        $canPurchaseCredits = $this->canPurchaseCredits();

        // ── Analítico de uso do mês corrente (consolidado em /panel/ai/usage) ──
        $quotaSnap  = $this->quotaService->currentMonthSnapshot($entityId);
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();

        $monthConsumedCredits = $quotaSnap['consumed_credits'];

        $monthRunsTotal = (int) AiRun::query()
            ->where('entity_id', $entityId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        $byWorkflow = AiRun::query()
            ->where('entity_id', $entityId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('workflow, count(*) as runs_count, sum(consumed_credits) as credits_total')
            ->groupBy('workflow')
            ->orderByDesc('runs_count')
            ->get()
            ->map(fn ($row) => [
                'workflow'      => (string) $row->workflow,
                'runs_count'    => (int) $row->runs_count,
                'credits_total' => (int) $row->credits_total,
            ])->all();

        $byMode = AiRun::query()
            ->where('entity_id', $entityId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('mode, count(*) as runs_count')
            ->groupBy('mode')
            ->orderByDesc('runs_count')
            ->get()
            ->map(fn ($row) => [
                // `mode` é castado para enum AiRunMode; usamos ->value para
                // serializar JSON corretamente (string).
                'mode'       => $row->mode?->value ?? '',
                'runs_count' => (int) $row->runs_count,
            ])->all();

        $approvalCounts = AiRun::query()
            ->where('entity_id', $entityId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $approvedCount = (int) ($approvalCounts[AiRunStatus::Approved->value] ?? 0);
        $rejectedCount = (int) ($approvalCounts[AiRunStatus::Rejected->value] ?? 0);
        $approvalTotal = $approvedCount + $rejectedCount;
        $approvalRate  = $approvalTotal > 0 ? round(($approvedCount / $approvalTotal) * 100, 1) : null;

        $topRuns = AiRun::query()
            ->where('entity_id', $entityId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->with(['patient.person:id,full_name', 'requestedBy:id,name'])
            ->orderByDesc('consumed_credits')
            ->limit(5)
            ->get()
            ->map(fn (AiRun $r) => [
                'id'               => $r->id,
                'workflow'         => (string) $r->workflow,
                'consumed_credits' => (int) $r->consumed_credits,
                'patient'          => $r->patient?->person?->full_name ?? $r->patient?->code,
                'requested_by'     => $r->requestedBy?->name,
                'created_at'       => $r->created_at?->format('d/m/Y H:i'),
            ])->all();

        $planQuota = $quotaSnap['monthly_quota'];

        return Inertia::render('Panel/AI/Index', [
            'balance'                  => $balance,
            'creditPackages'           => $canPurchaseCredits ? $this->creditPurchaseService->packages() : [],
            'recentCreditPurchases'    => $canPurchaseCredits ? $this->creditPurchaseService->recentForEntity($entityId) : [],
            'creditPurchaseAutoCredit' => (bool) config('ai.credit_purchases.auto_credit_without_gateway', false),
            'canPurchaseCredits'       => $canPurchaseCredits,
            'prefill'                  => $prefill,
            'analytics'                => [
                'period' => [
                    'start' => $monthStart->format('d/m/Y'),
                    'end'   => $monthEnd->format('d/m/Y'),
                    'label' => $monthStart->locale('pt_BR')->isoFormat('MMMM/YYYY'),
                ],
                'plan_quota' => $planQuota,
                'consumed'   => [
                    'credits'       => $monthConsumedCredits,
                    'runs'          => $monthRunsTotal,
                    'usage_percent' => $planQuota > 0
                        ? round(($monthConsumedCredits / $planQuota) * 100, 1)
                        : null,
                ],
                'by_workflow' => $byWorkflow,
                'by_mode'     => $byMode,
                'approval'    => [
                    'approved' => $approvedCount,
                    'rejected' => $rejectedCount,
                    'total'    => $approvalTotal,
                    'rate'     => $approvalRate,
                ],
                'top_runs' => $topRuns,
                // Onda 3, P4 — métricas operacionais
                'by_doctor'                => $this->analyticsService->byDoctor($entityId, $monthStart, $monthEnd),
                'avg_approve_time_seconds' => $this->analyticsService->averageApproveSeconds($entityId, $monthStart, $monthEnd),
                'avg_cost_per_record'      => $this->analyticsService->averageCostPerRecord($entityId, $monthStart, $monthEnd),
            ],
            'runs' => $runs->through(function (AiRun $run): array {
                return [
                    'id'                   => $run->id,
                    'workflow'             => $run->workflow,
                    'mode'                 => $run->mode?->value,
                    'risk_level'           => $run->risk_level?->value,
                    'status'               => $run->status?->value,
                    'estimated_credits'    => (int) $run->estimated_credits,
                    'reserved_credits'     => (int) $run->reserved_credits,
                    'consumed_credits'     => (int) $run->consumed_credits,
                    'requested_by'         => $run->requestedBy?->name,
                    'approved_by'          => $run->approvedBy?->name,
                    'patient'              => $run->patient?->person?->full_name ?? $run->patient?->code,
                    'medical_record_code'  => $run->medicalRecord?->code,
                    'provider_calls_count' => (int) $run->provider_calls_count,
                    'created_at'           => $run->created_at?->format('d/m/Y H:i'),
                    'approved_at'          => $run->approved_at?->format('d/m/Y H:i'),
                    'rejected_at'          => $run->rejected_at?->format('d/m/Y H:i'),
                    // Onda 4, C3 — badge "↩ Reanálise" no dashboard
                    'is_escalation' => $run->parent_run_id !== null,
                ];
            }),
            'patients'       => $this->patientsForEntity($entityId),
            'medicalRecords' => $this->medicalRecordsForEntity($entityId),
            'searchUrls'     => [
                'patients'        => route('panel.ai-runs.search.patients'),
                'medical_records' => route('panel.ai-runs.search.medical-records'),
            ],
            'filters'  => $request->only(['status']),
            'modes'    => $modes,
            'statuses' => array_map(static fn (AiRunStatus $status) => $status->value, AiRunStatus::cases()),
            'risks'    => [
                AiRiskLevel::Low->value,
                AiRiskLevel::Medium->value,
                AiRiskLevel::High->value,
            ],
            'workflows'    => $this->availableWorkflows($hasExamAssistant, $hasReportDrafting, $canConsensus),
            'defaultMode'  => $defaultMode,
            'canConsensus' => $canConsensus,
            'labels'       => $this->labels(),
        ]);
    }

    public function show(Request $request, AiRun $aiRun): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertRunBelongsToEntity($aiRun, $entityId);
        $this->assertAiFeatureEnabled($entityId);

        $aiRun->load([
            'patient.person:id,full_name',
            'medicalRecord:id,code',
            'requestedBy:id,name',
            'approvedBy:id,name',
            'providerCalls' => fn ($query) => $query->orderBy('created_at'),
            'parent:id,mode,status,workflow',
        ]);

        $parentSummary = null;

        if ($aiRun->parent_run_id && $aiRun->parent) {
            $parentSummary = [
                'id'       => (string) $aiRun->parent->id,
                'short_id' => substr((string) $aiRun->parent->id, 0, 8),
                'mode'     => $aiRun->parent->mode?->value,
                'status'   => $aiRun->parent->status?->value,
                'workflow' => (string) $aiRun->parent->workflow,
            ];
        }

        $data = [
            'id'         => $aiRun->id,
            'workflow'   => $aiRun->workflow,
            'mode'       => $aiRun->mode?->value,
            'risk_level' => $aiRun->risk_level?->value,
            'status'     => $aiRun->status?->value,
            // Onda 4, C3 — linhagem parent→escalation visível na UI
            'parent_run_id'    => $aiRun->parent_run_id ? (string) $aiRun->parent_run_id : null,
            'is_escalation'    => $aiRun->parent_run_id !== null,
            'parent_summary'   => $parentSummary,
            'current_role'     => $aiRun->current_role,
            'current_provider' => $aiRun->current_provider,
            'started_at'       => $aiRun->started_at?->toIso8601String(),
            'elapsed_ms'       => $aiRun->started_at
                ? max(0, (int) abs(now()->diffInMilliseconds($aiRun->started_at)))
                : null,
            'cancelled_at'        => $aiRun->cancelled_at?->format('d/m/Y H:i'),
            'estimated_credits'   => (int) $aiRun->estimated_credits,
            'reserved_credits'    => (int) $aiRun->reserved_credits,
            'consumed_credits'    => (int) $aiRun->consumed_credits,
            'input_summary'       => $aiRun->input_summary ?? [],
            'final_output'        => $aiRun->final_output,
            'safety_notes'        => $aiRun->safety_notes ?? [],
            'error_message'       => $aiRun->error_message,
            'patient'             => $aiRun->patient?->person?->full_name ?? $aiRun->patient?->code,
            'medical_record_code' => $aiRun->medicalRecord?->code,
            'requested_by'        => $aiRun->requestedBy?->name,
            'approved_by'         => $aiRun->approvedBy?->name,
            'approved_at'         => $aiRun->approved_at?->format('d/m/Y H:i'),
            'rejected_at'         => $aiRun->rejected_at?->format('d/m/Y H:i'),
            'provider_calls'      => $aiRun->providerCalls->map(function ($call): array {
                return [
                    'id'         => $call->id,
                    'role'       => $call->role?->value,
                    'status'     => $call->status,
                    'created_at' => $call->created_at?->format('d/m/Y H:i:s'),
                ];
            })->values()->all(),
        ];

        if ($request->expectsJson()) {
            return response()->json(['data' => $data]);
        }

        return redirect()->route('panel.dashboard');
    }

    /**
     * Histórico curto (até 5) de runs aprovados/em revisão de um paciente
     * específico, usado pelo painel inline para "Análises anteriores deste
     * paciente". Filtra por entity_id da sessão (cross-tenant guard).
     */
    public function listByPatient(Request $request, Patient $patient): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);
        abort_if((string) $patient->entity_id !== $entityId, 403);

        $runs = AiRun::query()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patient->id)
            ->whereIn('status', [
                AiRunStatus::Approved->value,
                AiRunStatus::WaitingApproval->value,
            ])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'workflow', 'mode', 'status', 'final_output', 'created_at', 'consumed_credits', 'parent_run_id']);

        return response()->json([
            'data' => $runs->map(fn (AiRun $run): array => [
                'id'               => (string) $run->id,
                'workflow'         => (string) $run->workflow,
                'workflow_label'   => __('ai.workflow_' . $run->workflow),
                'mode'             => $run->mode?->value,
                'status'           => $run->status?->value,
                'created_at'       => $run->created_at?->format('d/m/Y H:i'),
                'consumed_credits' => (int) $run->consumed_credits,
                'preview'          => mb_substr((string) $run->final_output, 0, 140),
                'final_output'     => (string) $run->final_output,
                // Onda 4, C3 — badge "↩ Reanálise" no histórico do paciente
                'is_escalation' => $run->parent_run_id !== null,
                'parent_run_id' => $run->parent_run_id ? (string) $run->parent_run_id : null,
            ])->values()->all(),
        ]);
    }

    /**
     * Autocomplete remoto de pacientes para o dashboard /panel/usage (Onda 3, P3).
     * Substitui o preload pesado de até 150 pacientes por uma busca sob demanda.
     */
    public function searchPatients(Request $request): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);

        $q = trim((string) $request->string('q'));

        $query = Patient::query()
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where('patients.entity_id', $entityId);

        if ($q !== '') {
            $query->where(function ($w) use ($q): void {
                $w->where('people.full_name', 'ilike', "%{$q}%")
                    ->orWhere('patients.code', 'ilike', "%{$q}%");
            });
        }

        $rows = $query->orderBy('people.full_name')
            ->limit(20)
            ->get(['patients.id', 'patients.code', 'people.full_name']);

        return response()->json([
            'data' => $rows->map(fn ($p): array => [
                'id'        => (string) $p->id,
                'label'     => (string) $p->full_name,
                'sub_label' => (string) $p->code,
            ])->values()->all(),
        ]);
    }

    /**
     * Autocomplete remoto de prontuários para o dashboard /panel/usage (Onda 3, P3).
     * Aceita filtro opcional por patient_id para acelerar a busca quando o
     * paciente já foi selecionado.
     */
    public function searchMedicalRecords(Request $request): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);

        $q         = trim((string) $request->string('q'));
        $patientId = $request->string('patient_id')->trim()->value();

        $query = MedicalRecord::query()
            ->with('patient.person:id,full_name')
            ->where('entity_id', $entityId);

        if ($patientId !== '') {
            $query->where('patient_id', $patientId);
        }

        if ($q !== '') {
            $query->where('code', 'ilike', "%{$q}%");
        }

        $rows = $query->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'code', 'patient_id', 'created_at']);

        return response()->json([
            'data' => $rows->map(fn (MedicalRecord $r): array => [
                'id'        => (string) $r->id,
                'label'     => (string) $r->code,
                'sub_label' => (string) ($r->patient?->person?->full_name ?? '') . ' · ' . ($r->created_at?->format('d/m/Y') ?? ''),
            ])->values()->all(),
        ]);
    }

    public function estimate(EstimateAiRunRequest $request): JsonResponse
    {
        $entityId     = $this->selectedEntityId();
        $canConsensus = $this->canConsensusForEntity($entityId);
        $payload      = $this->enricher->enrich($request->validated(), $entityId, $canConsensus);
        $estimate     = $this->estimateService->estimate($payload);
        $balance      = $this->walletService->balance($entityId);

        return response()->json([
            'estimate'   => $this->estimateService->publicEstimate($estimate),
            'balance'    => $balance,
            'guardrails' => $payload['_guardrails'] ?? [],
        ]);
    }

    public function store(StoreAiRunRequest $request): JsonResponse|RedirectResponse
    {
        $entityId       = $this->selectedEntityId();
        $canConsensus   = $this->canConsensusForEntity($entityId);
        $payload        = $this->enricher->enrich($request->validated(), $entityId, $canConsensus);
        $estimate       = $this->estimateService->estimate($payload);
        $subscriptionId = $this->activeSubscriptionId($entityId);

        try {
            $run = DB::transaction(function () use ($entityId, $payload, $estimate, $subscriptionId): AiRun {
                $run = AiRun::query()->create([
                    'entity_id'         => $entityId,
                    'patient_id'        => $payload['patient_id'] ?? null,
                    'medical_record_id' => $payload['medical_record_id'] ?? null,
                    'requested_by'      => (string) auth()->id(),
                    'workflow'          => $payload['workflow'],
                    'mode'              => $payload['mode'],
                    'risk_level'        => $payload['risk_level'],
                    'status'            => AiRunStatus::Pending->value,
                    'estimated_credits' => $estimate->normalizedCredits,
                    'reserved_credits'  => 0,
                    'consumed_credits'  => 0,
                    'input_summary'     => [
                        'user_prompt'   => $payload['user_prompt'],
                        'system_prompt' => $payload['system_prompt'] ?? null,
                        'context'       => $payload['context'] ?? [],
                        'attachments'   => $payload['attachments'] ?? [],
                        // Eye Image: guardamos só as referências dos exames (o
                        // base64 é reconstruído na execução, nunca persistido).
                        'exam_ids'          => array_values((array) ($payload['exam_ids'] ?? [])),
                        'expects_json'      => (bool) ($payload['expects_json'] ?? false),
                        'max_output_tokens' => $payload['max_output_tokens'] ?? null,
                        'metadata'          => [
                            'source'     => 'panel_ai_ui',
                            'guardrails' => $payload['_guardrails'] ?? [],
                        ],
                    ],
                    'safety_notes' => $this->estimateService->guardrailSafetyNotes($payload['_guardrails'] ?? []),
                ]);

                // Vincula os exames analisados ao run (laudo no módulo Eye Image).
                $examIds = array_values((array) ($payload['exam_ids'] ?? []));

                if ($examIds !== []) {
                    DB::table('ai_run_patient_exam')->insert(array_map(fn (string $examId): array => [
                        'ai_run_id'       => (string) $run->id,
                        'patient_exam_id' => $examId,
                        'entity_id'       => $entityId,
                        'created_at'      => now(),
                    ], $examIds));
                }

                $this->walletService->reserve(
                    entityId: $entityId,
                    amount: $estimate->normalizedCredits,
                    aiRunId: (string) $run->id,
                    subscriptionId: $subscriptionId,
                    description: 'Reserva de créditos para execução de IA.',
                    idempotencyKey: "ai-run:{$run->id}:reserve",
                    createdBy: (string) auth()->id(),
                    metadata: [
                        'workflow' => $payload['workflow'],
                        'mode'     => $payload['mode'],
                    ],
                );

                $run->update([
                    'status'           => AiRunStatus::Reserved->value,
                    'reserved_credits' => $estimate->normalizedCredits,
                ]);

                return $run->fresh();
            });
        } catch (InsufficientAiCreditsException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('ai.insufficient_credits'),
                    'details' => [
                        'requested' => $e->requested,
                        'available' => $e->available,
                    ],
                ], 422);
            }

            return back()->with('error', __('ai.insufficient_credits'));
        }

        // afterCommit garante que o worker só lê o AiRun depois que a transaction
        // de reserva foi efetivamente commitada — protege contra race conditions
        // se a transaction for retentada ou se dispatchSync for usado.
        RunAiWorkflowJob::dispatch((string) $run->id)->afterCommit();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('ai.run_created'),
                'run_id'  => $run->id,
                'status'  => $run->status?->value,
            ], 201);
        }

        return redirect()
            ->route('panel.dashboard')
            ->with('success', __('ai.run_created'));
    }

    public function approve(Request $request, AiRun $aiRun): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertRunBelongsToEntity($aiRun, $entityId);
        $this->assertAiFeatureEnabled($entityId);
        $this->authorizeDoctorApproval($entityId);

        $validated = $request->validate([
            'final_output'   => ['nullable', 'string', 'max:65000'],
            'original_draft' => ['nullable', 'string', 'max:65000'],
        ]);

        $finalOutput = isset($validated['final_output']) && trim($validated['final_output']) !== ''
            ? (string) $validated['final_output']
            : (string) $aiRun->final_output;

        // Trilha de auditoria CFM: se o front enviou o rascunho original, persiste
        // em input_summary.original_draft para responder "o que o médico mudou?"
        // sem precisar inspecionar provider calls.
        $originalDraft = isset($validated['original_draft']) && trim($validated['original_draft']) !== ''
            ? (string) $validated['original_draft']
            : null;

        $persistResult = ['attached' => false, 'requires_record_confirmation' => false, 'consultation_date' => null];

        try {
            DB::transaction(function () use ($aiRun, $finalOutput, $originalDraft, &$persistResult): void {
                $lockedRun = AiRun::query()
                    ->whereKey($aiRun->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->status !== AiRunStatus::WaitingApproval) {
                    throw new DomainException('invalid_status_transition');
                }

                $updates = [
                    'status'        => AiRunStatus::Approved->value,
                    'approved_by'   => (string) auth()->id(),
                    'approved_at'   => now(),
                    'final_output'  => $finalOutput,
                    'error_message' => null,
                ];

                if ($originalDraft !== null && $originalDraft !== $finalOutput) {
                    $summary                     = is_array($lockedRun->input_summary) ? $lockedRun->input_summary : [];
                    $summary['original_draft']   = $originalDraft;
                    $summary['edited_by_doctor'] = true;
                    $summary['edited_at']        = now()->toIso8601String();
                    $updates['input_summary']    = $summary;
                }

                $lockedRun->update($updates);

                $persistResult = $this->documentationService->persistFromApprovedRun($lockedRun->fresh(), $finalOutput);
            });
        } catch (DomainException) {
            return $this->statusTransitionErrorResponse($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('ai.run_approved'),
                'status'  => AiRunStatus::Approved->value,
                'run_id'  => (string) $aiRun->id,
                // Sem prontuário do dia da consulta: o front pergunta ao médico
                // se pode abrir um novo prontuário para receber o laudo.
                'requires_record_confirmation' => (bool) $persistResult['requires_record_confirmation'],
                'consultation_date'            => $persistResult['consultation_date'],
            ]);
        }

        return back()->with('success', __('ai.run_approved'));
    }

    /**
     * Abre um novo prontuário (do dia) para receber o laudo de um run já aprovado,
     * quando o médico confirma. Idempotente: se já houver documentação para o run,
     * apenas retorna sucesso.
     */
    public function openRecordForRun(Request $request, AiRun $aiRun): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertRunBelongsToEntity($aiRun, $entityId);
        $this->assertAiFeatureEnabled($entityId);
        $this->authorizeDoctorApproval($entityId);

        if ($aiRun->status !== AiRunStatus::Approved) {
            return $this->statusTransitionErrorResponse($request);
        }

        $patientId = $this->documentationService->resolveRunPatientId($aiRun);

        if (! $patientId) {
            abort(422, __('ai.record_patient_missing'));
        }

        // Abrir prontuário é ato médico: exige um Doctor (do aprovador ou do exame).
        $doctorId = $this->documentationService->resolveDoctorIdForCurrentUser($entityId)
            ?? PatientExam::query()
                ->whereIn('id', (array) ($aiRun->input_summary['exam_ids'] ?? []))
                ->value('doctor_id');

        if (! $doctorId) {
            abort(422, __('ai.record_doctor_required'));
        }

        // Idempotência: laudo já documentado em algum prontuário.
        $existing = MedicalRecordDocumentation::query()->where('ai_run_id', $aiRun->id)->exists();

        if (! $existing) {
            // Vincula o novo prontuário ao agendamento da consulta, quando houver.
            [$scheduleId] = $this->documentationService->consultationAnchorForRun($aiRun);

            DB::transaction(function () use ($aiRun, $entityId, $patientId, $doctorId, $scheduleId): void {
                $record = MedicalRecord::query()->create(array_filter([
                    'entity_id'   => $entityId,
                    'patient_id'  => $patientId,
                    'doctor_id'   => (string) $doctorId,
                    'schedule_id' => $scheduleId,
                ]));

                $this->documentationService->writeRunDocumentation($aiRun, $record, (string) $aiRun->final_output);
            });
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('ai.record_opened'),
                'status'  => AiRunStatus::Approved->value,
            ]);
        }

        return back()->with('success', __('ai.record_opened'));
    }

    /**
     * Cancela cooperativamente uma execução em Pending/Reserved/Running. Em
     * Pending/Reserved, marca direto como Cancelled e libera a reserva inteira.
     * Em Running, marca `cancelled_at` e o orchestrator faz a transição final
     * no próximo gap entre roles (libera apenas o saldo NÃO consumido).
     *
     * Idempotente: re-cancelar um run já cancelado responde 200.
     */
    public function cancel(Request $request, AiRun $aiRun): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertRunBelongsToEntity($aiRun, $entityId);
        $this->assertAiFeatureEnabled($entityId);
        $this->authorizeCancellation($aiRun, $entityId);

        $willSettleAsync = false;

        try {
            $willSettleAsync = DB::transaction(function () use ($aiRun): bool {
                $lockedRun = AiRun::query()
                    ->whereKey($aiRun->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->status === AiRunStatus::Cancelled) {
                    return false; // idempotente
                }

                $isPreExecution = in_array($lockedRun->status, [
                    AiRunStatus::Pending,
                    AiRunStatus::Reserved,
                ], true);

                $isRunning = $lockedRun->status === AiRunStatus::Running;

                if (! $isPreExecution && ! $isRunning) {
                    throw new DomainException('invalid_status_transition');
                }

                $lockedRun->update([
                    'cancelled_at' => now(),
                    'cancelled_by' => (string) auth()->id(),
                ]);

                if ($isPreExecution) {
                    app(AiRunExecutionService::class)
                        ->compensateCancelledRun($lockedRun->fresh());

                    return false;
                }

                // Running: orchestrator faz a transição final no próximo checkpoint.
                return true;
            });
        } catch (DomainException) {
            return $this->statusTransitionErrorResponse($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message'           => __('ai.run_cancelled'),
                'status'            => $aiRun->fresh()?->status?->value,
                'will_settle_async' => $willSettleAsync,
            ]);
        }

        return back()->with('success', __('ai.run_cancelled'));
    }

    /**
     * Reanalisa um run terminal com o modo IMEDIATAMENTE SUPERIOR
     * (Economy → Validated → Consensus) reaproveitando todo o input_summary
     * original. Cria um novo AiRun com parent_run_id ligando ao original.
     *
     * Aceita: Approved, Rejected, Failed, Cancelled.
     * Rejeita: Pending, Reserved, Running, WaitingApproval (não-terminais).
     */
    public function escalate(Request $request, AiRun $aiRun): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertRunBelongsToEntity($aiRun, $entityId);
        $this->assertAiFeatureEnabled($entityId);

        // Estados terminais elegíveis.
        $terminal = [
            AiRunStatus::Approved,
            AiRunStatus::Rejected,
            AiRunStatus::Failed,
            AiRunStatus::Cancelled,
        ];

        if (! in_array($aiRun->status, $terminal, true)) {
            return $this->statusTransitionErrorResponse($request);
        }

        $nextMode = match ($aiRun->mode) {
            AiRunMode::Economy   => AiRunMode::Validated,
            AiRunMode::Validated => AiRunMode::Consensus,
            AiRunMode::Consensus => null,
            default              => null,
        };

        if ($nextMode === null) {
            return $request->expectsJson()
                ? response()->json(['message' => __('ai.escalate.no_higher_mode')], 422)
                : back()->with('error', __('ai.escalate.no_higher_mode'));
        }

        $canConsensus = $this->canConsensusForEntity($entityId);

        if (! in_array($nextMode->value, $this->availableModes($canConsensus), true)) {
            return $request->expectsJson()
                ? response()->json(['message' => __('ai.mode_unavailable')], 422)
                : back()->with('error', __('ai.mode_unavailable'));
        }

        // Reconstrói o payload a partir do input_summary do run original. O
        // contexto/sistema/exam_ids já vieram enriquecidos no run anterior — não
        // re-enriquecemos para evitar drift (paciente/prontuário podem ter mudado).
        $summary = is_array($aiRun->input_summary) ? $aiRun->input_summary : [];
        $payload = [
            'workflow'          => (string) $aiRun->workflow,
            'mode'              => $nextMode->value,
            'risk_level'        => $aiRun->risk_level?->value ?? 'medium',
            'patient_id'        => $aiRun->patient_id,
            'medical_record_id' => $aiRun->medical_record_id,
            'user_prompt'       => (string) ($summary['user_prompt'] ?? ''),
            'system_prompt'     => $summary['system_prompt'] ?? null,
            'context'           => (array) ($summary['context'] ?? []),
            'attachments'       => (array) ($summary['attachments'] ?? []),
            'exam_ids'          => array_values((array) ($summary['exam_ids'] ?? [])),
            'expects_json'      => (bool) ($summary['expects_json'] ?? false),
            'max_output_tokens' => $summary['max_output_tokens'] ?? null,
            '_guardrails'       => (array) ($summary['metadata']['guardrails'] ?? []),
        ];

        // Imagem ocular: precisa de exam_ids para a sobretaxa de tokens da estimativa.
        if (($payload['workflow'] ?? '') === 'eye_image_analysis') {
            $payload['_image_count'] = count($payload['exam_ids']);
        }

        $estimate       = $this->estimateService->estimate($payload);
        $subscriptionId = $this->activeSubscriptionId($entityId);

        try {
            $newRun = DB::transaction(function () use ($aiRun, $entityId, $payload, $estimate, $subscriptionId): AiRun {
                $run = AiRun::query()->create([
                    'entity_id'         => $entityId,
                    'patient_id'        => $payload['patient_id'],
                    'medical_record_id' => $payload['medical_record_id'],
                    'requested_by'      => (string) auth()->id(),
                    'parent_run_id'     => (string) $aiRun->id,
                    'workflow'          => $payload['workflow'],
                    'mode'              => $payload['mode'],
                    'risk_level'        => $payload['risk_level'],
                    'status'            => AiRunStatus::Pending->value,
                    'estimated_credits' => $estimate->normalizedCredits,
                    'reserved_credits'  => 0,
                    'consumed_credits'  => 0,
                    'input_summary'     => [
                        'user_prompt'       => $payload['user_prompt'],
                        'system_prompt'     => $payload['system_prompt'],
                        'context'           => $payload['context'],
                        'attachments'       => $payload['attachments'],
                        'exam_ids'          => $payload['exam_ids'],
                        'expects_json'      => $payload['expects_json'],
                        'max_output_tokens' => $payload['max_output_tokens'],
                        'metadata'          => [
                            'source'        => 'panel_ai_escalate',
                            'guardrails'    => $payload['_guardrails'] ?? [],
                            'parent_run_id' => (string) $aiRun->id,
                            'parent_mode'   => $aiRun->mode?->value,
                        ],
                    ],
                    'safety_notes' => $this->estimateService->guardrailSafetyNotes($payload['_guardrails'] ?? []),
                ]);

                if ($payload['exam_ids'] !== []) {
                    DB::table('ai_run_patient_exam')->insert(array_map(fn (string $examId): array => [
                        'ai_run_id'       => (string) $run->id,
                        'patient_exam_id' => $examId,
                        'entity_id'       => $entityId,
                        'created_at'      => now(),
                    ], $payload['exam_ids']));
                }

                $this->walletService->reserve(
                    entityId: $entityId,
                    amount: $estimate->normalizedCredits,
                    aiRunId: (string) $run->id,
                    subscriptionId: $subscriptionId,
                    description: 'Reserva de créditos para reanálise (escalate).',
                    idempotencyKey: "ai-run:{$run->id}:reserve",
                    createdBy: (string) auth()->id(),
                    metadata: [
                        'workflow'      => $payload['workflow'],
                        'mode'          => $payload['mode'],
                        'parent_run_id' => (string) $aiRun->id,
                    ],
                );

                $run->update([
                    'status'           => AiRunStatus::Reserved->value,
                    'reserved_credits' => $estimate->normalizedCredits,
                ]);

                return $run->fresh();
            });
        } catch (InsufficientAiCreditsException $e) {
            return $request->expectsJson()
                ? response()->json([
                    'message' => __('ai.insufficient_credits'),
                    'details' => ['requested' => $e->requested, 'available' => $e->available],
                ], 422)
                : back()->with('error', __('ai.insufficient_credits'));
        }

        RunAiWorkflowJob::dispatch((string) $newRun->id)->afterCommit();

        if ($request->expectsJson()) {
            return response()->json([
                'run_id' => (string) $newRun->id,
                'status' => $newRun->status?->value,
                'mode'   => $newRun->mode?->value,
            ], 201);
        }

        return back()->with('success', __('ai.run_created'));
    }

    public function reject(Request $request, AiRun $aiRun): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertRunBelongsToEntity($aiRun, $entityId);
        $this->assertAiFeatureEnabled($entityId);
        $this->authorizeDoctorApproval($entityId);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($aiRun, $validated): void {
                $lockedRun = AiRun::query()
                    ->whereKey($aiRun->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->status !== AiRunStatus::WaitingApproval) {
                    throw new DomainException('invalid_status_transition');
                }

                $lockedRun->update([
                    'status'        => AiRunStatus::Rejected->value,
                    'rejected_at'   => now(),
                    'error_message' => $validated['reason'] ?? null,
                ]);
            });
        } catch (DomainException) {
            return $this->statusTransitionErrorResponse($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('ai.run_rejected'),
                'status'  => AiRunStatus::Rejected->value,
            ]);
        }

        return back()->with('success', __('ai.run_rejected'));
    }

    /**
     * Recebe feedback do médico após edição pesada (>30%) do rascunho da IA
     * (Onda 3, P5). Persiste tags estruturadas + nota livre opcional. Idempotente
     * por ai_run_id — re-submit substitui o anterior.
     */
    public function feedback(Request $request, AiRun $aiRun): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertRunBelongsToEntity($aiRun, $entityId);
        $this->assertAiFeatureEnabled($entityId);

        $userId = (string) auth()->id();
        $isOwn  = (string) $aiRun->requested_by === $userId || (string) $aiRun->approved_by === $userId;
        abort_if(! $isOwn, 403);

        $validated = $request->validate([
            'edit_ratio_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'tags'               => ['nullable', 'array'],
            'tags.*'             => ['string', 'in:' . implode(',', AiFeedbackService::ALLOWED_TAGS)],
            'note'               => ['nullable', 'string', 'max:2000'],
        ]);

        $doctorId = $this->documentationService->resolveDoctorIdForCurrentUser($entityId);

        $this->feedbackService->submit(
            run: $aiRun,
            editRatioPercent: (int) $validated['edit_ratio_percent'],
            tags: (array) ($validated['tags'] ?? []),
            note: $validated['note'] ?? null,
            doctorId: $doctorId,
        );

        return $request->expectsJson()
            ? response()->json(['status' => 'saved'])
            : back()->with('success', __('ai.feedback_saved'));
    }

    private function selectedEntityId(): string
    {
        return (string) session('selected_entity_id');
    }

    private function assertAiFeatureEnabled(string $entityId): void
    {
        $hasExamAssistant  = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $hasReportDrafting = $this->featureGate->can($entityId, FeatureKey::HasAiReportDrafting);
        $hasEyeImage       = $this->featureGate->can($entityId, FeatureKey::HasAiEyeImageAnalysis);

        if (! $hasExamAssistant && ! $hasReportDrafting && ! $hasEyeImage) {
            abort(403, __('ai.feature_unavailable'));
        }
    }

    private function authorizeDoctorApproval(string $entityId): void
    {
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    /**
     * Cancelamento é mais permissivo que aprovação: o próprio solicitante pode
     * cancelar o run dele a qualquer momento (UX do painel). Quem tem o gate de
     * laudo (IssueReport) também pode cancelar o de terceiros.
     */
    private function authorizeCancellation(AiRun $aiRun, string $entityId): void
    {
        if ((string) $aiRun->requested_by === (string) auth()->id()) {
            return;
        }

        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    private function assertRunBelongsToEntity(AiRun $aiRun, string $entityId): void
    {
        abort_if((string) $aiRun->entity_id !== $entityId, 403);
    }

    /**
     * @return list<string>
     */
    private function availableModes(bool $canConsensus): array
    {
        // Modos disponíveis escalam com o número de provedores HABILITADOS
        // (1 -> economia, 2 -> +validado, 3 -> +consenso). O consenso ainda
        // depende do recurso da entidade (canConsensus).
        $modes = [];

        foreach ($this->providerSettings->availableModes() as $mode) {
            if ($mode === AiRunMode::Consensus && ! $canConsensus) {
                continue;
            }
            $modes[] = $mode->value;
        }

        return $modes;
    }

    /**
     * @return list<string>
     */
    private function availableWorkflows(bool $hasExamAssistant, bool $hasReportDrafting, bool $canConsensus): array
    {
        $workflows = array_values(array_filter([
            $hasExamAssistant ? 'exam_assistant' : null,
            $hasReportDrafting ? 'report_drafting' : null,
        ]));

        if ($canConsensus) {
            $workflows[] = 'consensus_review';
        }

        return $workflows;
    }

    private function canConsensusForEntity(string $entityId): bool
    {
        // Consenso exige a flag de config, 3+ provedores habilitados (settings)
        // e o recurso liberado para a entidade.
        if (! $this->providerSettings->isModeAvailable(AiRunMode::Consensus)) {
            return false;
        }

        return $this->featureGate->can($entityId, FeatureKey::HasAiConsensus);
    }

    private function activeSubscriptionId(string $entityId): ?string
    {
        return Subscription::query()
            ->forEntity($entityId)
            ->accessible()
            ->latest('created_at')
            ->value('id');
    }

    private function canPurchaseCredits(): bool
    {
        return session('selected_entity_user_rule') === ClientRule::Admin->value;
    }

    /**
     * Seed inicial leve (top 20) para o dashboard /panel/usage. A busca completa
     * é feita via autocomplete remoto (Onda 3, P3 — searchPatients).
     *
     * @return array<int, array{id:string,name:string,code:string}>
     */
    private function patientsForEntity(string $entityId): array
    {
        return Patient::query()
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where('patients.entity_id', $entityId)
            ->orderBy('people.full_name')
            ->limit(20)
            ->get(['patients.id', 'patients.code', 'people.full_name'])
            ->map(fn ($patient): array => [
                'id'   => (string) $patient->id,
                'code' => (string) $patient->code,
                'name' => (string) $patient->full_name,
            ])
            ->values()
            ->all();
    }

    /**
     * Seed inicial leve (top 20) para o dashboard /panel/usage. Busca completa
     * via searchMedicalRecords (Onda 3, P3).
     *
     * @return array<int, array{id:string,code:string,patient_id:string,patient_name:string,created_at:string|null}>
     */
    private function medicalRecordsForEntity(string $entityId): array
    {
        return MedicalRecord::query()
            ->with('patient.person:id,full_name')
            ->where('entity_id', $entityId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'code', 'patient_id', 'created_at'])
            ->map(fn (MedicalRecord $record): array => [
                'id'           => (string) $record->id,
                'code'         => (string) $record->code,
                'patient_id'   => (string) $record->patient_id,
                'patient_name' => (string) ($record->patient?->person?->full_name ?? ''),
                'created_at'   => $record->created_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'title'                       => __('ai.title'),
            'subtitle'                    => __('ai.subtitle'),
            'support_notice'              => __('ai.support_notice'),
            'estimate'                    => __('ai.estimate'),
            'run'                         => __('ai.run'),
            'approve'                     => __('ai.approve'),
            'reject'                      => __('ai.reject'),
            'mode_economy'                => __('ai.mode_economy'),
            'mode_validated'              => __('ai.mode_validated'),
            'mode_consensus'              => __('ai.mode_consensus'),
            'risk_low'                    => __('ai.risk_low'),
            'risk_medium'                 => __('ai.risk_medium'),
            'risk_high'                   => __('ai.risk_high'),
            'workflow_exam_assistant'     => __('ai.workflow_exam_assistant'),
            'workflow_report_drafting'    => __('ai.workflow_report_drafting'),
            'workflow_consensus_review'   => __('ai.workflow_consensus_review'),
            'dashboard'                   => __('actions.sidemenu.dashboard'),
            'credits_available'           => __('ai.credits_available'),
            'credits_reserved'            => __('ai.credits_reserved'),
            'credits_total'               => __('ai.credits_total'),
            'credit_packages_title'       => __('ai.credit_packages_title'),
            'credit_packages_subtitle'    => __('ai.credit_packages_subtitle'),
            'credit_package_unit'         => __('ai.credit_package_unit'),
            'credit_package_buy'          => __('ai.credit_package_buy'),
            'credit_package_request'      => __('ai.credit_package_request'),
            'credit_package'              => __('ai.credit_package'),
            'amount'                      => __('ai.amount'),
            'credit_purchase_history'     => __('ai.credit_purchase_history'),
            'credit_purchase_empty'       => __('ai.credit_purchase_empty'),
            'credit_purchase_pending'     => __('ai.credit_purchase_pending'),
            'credit_purchase_unavailable' => __('ai.credit_purchase_unavailable'),
            'usage_dashboard'             => __('ai.dashboard.title'),
            'workflow'                    => __('ai.workflow'),
            'mode'                        => __('ai.mode'),
            'risk'                        => __('ai.risk'),
            'max_output_tokens'           => __('ai.max_output_tokens'),
            'patient_optional'            => __('ai.patient_optional'),
            'medical_record_optional'     => __('ai.medical_record_optional'),
            'select_placeholder'          => __('ai.select_placeholder'),
            'system_prompt'               => __('ai.system_prompt'),
            'clinical_prompt'             => __('ai.clinical_prompt'),
            'clinical_prompt_placeholder' => __('ai.clinical_prompt_placeholder'),
            'estimated_credits'           => __('ai.estimated_credits'),
            'raw_cost_usd'                => __('ai.raw_cost_usd'),
            'runs'                        => __('ai.runs'),
            'all_statuses'                => __('ai.all_statuses'),
            'date'                        => __('ai.date'),
            'status'                      => __('actions.status'),
            'credits'                     => __('ai.credits'),
            'details'                     => __('actions.details'),
            'empty_runs'                  => __('ai.empty_runs'),
            'loading'                     => __('actions.loading'),
            'select_run'                  => __('ai.select_run'),
            'patient'                     => __('actions.patient'),
            'medical_record'              => __('ai.medical_record'),
            'editable_draft'              => __('ai.editable_draft'),
            'rejection_reason_optional'   => __('ai.rejection_reason_optional'),
            'status_pending'              => __('ai.status_pending'),
            'status_reserved'             => __('ai.status_reserved'),
            'status_running'              => __('ai.status_running'),
            'status_waiting_approval'     => __('ai.status_waiting_approval'),
            'status_approved'             => __('ai.status_approved'),
            'status_rejected'             => __('ai.status_rejected'),
            'status_failed'               => __('ai.status_failed'),
            'status_cancelled'            => __('ai.status_cancelled'),
        ];
    }

    private function statusTransitionErrorResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('ai.invalid_status_transition'),
            ], 422);
        }

        return back()->with('error', __('ai.invalid_status_transition'));
    }
}
