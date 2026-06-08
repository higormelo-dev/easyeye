<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Exceptions\{AiModelPriceNotFoundException, InsufficientAiCreditsException};
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiCreditPurchaseService, AiCreditWalletService, AiMedicalContextBuilder, AiPricingService, AiPromptGuardrailService, AiProviderSettings};
use App\DTOs\AI\AiCreditEstimateData;
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DocumentationType, EntityGate, FeatureKey};
use App\Jobs\AI\RunAiWorkflowJob;
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, Schedule, Subscription};
use App\Services\FeatureGateService;
use DomainException;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Gate};
use Inertia\{Inertia, Response as InertiaResponse};
use Mews\Purifier\Facades\Purifier;

class AiRunsController extends Controller
{
    public function __construct(
        private readonly AiCreditWalletService $walletService,
        private readonly AiPricingService $pricingService,
        private readonly FeatureGateService $featureGate,
        private readonly AiMedicalContextBuilder $contextBuilder,
        private readonly AiCreditPurchaseService $creditPurchaseService,
        private readonly AiPromptGuardrailService $promptGuardrails,
        private readonly AiProviderSettings $providerSettings,
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
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();

        $monthConsumedCredits = (int) AiRun::query()
            ->where('entity_id', $entityId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->whereIn('status', [AiRunStatus::Approved->value, AiRunStatus::WaitingApproval->value])
            ->sum('consumed_credits');

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
                'mode'       => (string) $row->mode,
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

        $planQuota = $this->planQuotaForEntity($entityId);

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
                ];
            }),
            'patients'       => $this->patientsForEntity($entityId),
            'medicalRecords' => $this->medicalRecordsForEntity($entityId),
            'filters'        => $request->only(['status']),
            'modes'          => $modes,
            'statuses'       => array_map(static fn (AiRunStatus $status) => $status->value, AiRunStatus::cases()),
            'risks'          => [
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
        ]);

        $data = [
            'id'                  => $aiRun->id,
            'workflow'            => $aiRun->workflow,
            'mode'                => $aiRun->mode?->value,
            'risk_level'          => $aiRun->risk_level?->value,
            'status'              => $aiRun->status?->value,
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

    public function estimate(Request $request): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);

        $payload  = $this->validatedPayload($request, $entityId);
        $estimate = $this->estimateCreditsForPayload($payload);
        $balance  = $this->walletService->balance($entityId);

        return response()->json([
            'estimate'   => $this->publicEstimate($estimate),
            'balance'    => $balance,
            'guardrails' => $payload['_guardrails'] ?? [],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);
        $payload        = $this->validatedPayload($request, $entityId);
        $estimate       = $this->estimateCreditsForPayload($payload);
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
                    'safety_notes' => $this->guardrailSafetyNotes($payload['_guardrails'] ?? []),
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
            'final_output' => ['nullable', 'string', 'max:65000'],
        ]);

        $finalOutput = isset($validated['final_output']) && trim($validated['final_output']) !== ''
            ? (string) $validated['final_output']
            : (string) $aiRun->final_output;

        $persistResult = ['attached' => false, 'requires_record_confirmation' => false, 'consultation_date' => null];

        try {
            DB::transaction(function () use ($aiRun, $finalOutput, &$persistResult): void {
                $lockedRun = AiRun::query()
                    ->whereKey($aiRun->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->status !== AiRunStatus::WaitingApproval) {
                    throw new DomainException('invalid_status_transition');
                }

                $lockedRun->update([
                    'status'        => AiRunStatus::Approved->value,
                    'approved_by'   => (string) auth()->id(),
                    'approved_at'   => now(),
                    'final_output'  => $finalOutput,
                    'error_message' => null,
                ]);

                $persistResult = $this->persistDocumentationFromApprovedRun($lockedRun->fresh(), $finalOutput);
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

        $patientId = $this->resolveRunPatientId($aiRun);

        if (! $patientId) {
            abort(422, __('ai.record_patient_missing'));
        }

        // Abrir prontuário é ato médico: exige um Doctor (do aprovador ou do exame).
        $doctorId = $this->resolveDoctorIdForCurrentUser($entityId)
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
            [$scheduleId] = $this->consultationAnchorForRun($aiRun);

            DB::transaction(function () use ($aiRun, $entityId, $patientId, $doctorId, $scheduleId): void {
                $record = MedicalRecord::query()->create(array_filter([
                    'entity_id'   => $entityId,
                    'patient_id'  => $patientId,
                    'doctor_id'   => (string) $doctorId,
                    'schedule_id' => $scheduleId,
                ]));

                $this->writeRunDocumentation($aiRun, $record, (string) $aiRun->final_output);
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
     * Persiste o laudo no prontuário do DIA DA CONSULTA (agendamento). Regra:
     *  - Run já vinculado a um prontuário específico -> grava nele.
     *  - Senão, procura o prontuário do paciente da consulta: mesmo agendamento
     *    (schedule_id) e, na falta, qualquer prontuário do mesmo dia do agendamento.
     *  - Sem prontuário desse dia, NÃO cria automaticamente: sinaliza
     *    `requires_record_confirmation` para o médico decidir abrir (CFM/LGPD).
     *
     * Idempotência via `updateOrCreate` por `ai_run_id`.
     *
     * @return array{attached: bool, requires_record_confirmation: bool, consultation_date: ?string}
     */
    private function persistDocumentationFromApprovedRun(AiRun $aiRun, string $finalOutput): array
    {
        $consultationDate = null;

        if ($aiRun->medical_record_id) {
            $recordQuery = MedicalRecord::query()
                ->where('id', (string) $aiRun->medical_record_id)
                ->where('entity_id', (string) $aiRun->entity_id);

            if (! empty($aiRun->patient_id)) {
                $recordQuery->where('patient_id', (string) $aiRun->patient_id);
            }

            $record = $recordQuery->first();
        } else {
            $patientId = $this->resolveRunPatientId($aiRun);

            if (! $patientId) {
                return ['attached' => false, 'requires_record_confirmation' => false, 'consultation_date' => null];
            }

            [$scheduleId, $consultationDate] = $this->consultationAnchorForRun($aiRun);
            $record                          = $this->findConsultationRecord((string) $aiRun->entity_id, $patientId, $scheduleId, $consultationDate);

            // Sem prontuário do dia da consulta -> pergunta ao médico se pode abrir.
            if (! $record) {
                return ['attached' => false, 'requires_record_confirmation' => true, 'consultation_date' => $consultationDate];
            }
        }

        if (! $record) {
            return ['attached' => false, 'requires_record_confirmation' => false, 'consultation_date' => $consultationDate];
        }

        $this->writeRunDocumentation($aiRun, $record, $finalOutput);

        return ['attached' => true, 'requires_record_confirmation' => false, 'consultation_date' => $consultationDate];
    }

    /**
     * Prontuário da consulta: prioriza o do MESMO agendamento; na falta, qualquer
     * prontuário do paciente no mesmo DIA do agendamento.
     */
    private function findConsultationRecord(string $entityId, string $patientId, ?string $scheduleId, string $consultationDate): ?MedicalRecord
    {
        $base = MedicalRecord::query()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId);

        if ($scheduleId) {
            $record = (clone $base)->where('schedule_id', $scheduleId)->latest('created_at')->first();

            if ($record) {
                return $record;
            }
        }

        return (clone $base)->whereDate('created_at', $consultationDate)->latest('created_at')->first();
    }

    /**
     * Âncora da consulta = agendamento dos exames analisados: devolve
     * [schedule_id, data]. Sem agendamento vinculado, cai para hoje.
     *
     * @return array{0: ?string, 1: string}
     */
    private function consultationAnchorForRun(AiRun $aiRun): array
    {
        $scheduleId = PatientExam::query()
            ->whereIn('id', (array) ($aiRun->input_summary['exam_ids'] ?? []))
            ->whereNotNull('schedule_id')
            ->orderByDesc('created_at')
            ->value('schedule_id');

        if ($scheduleId) {
            $dateTime = Schedule::query()->whereKey($scheduleId)->value('date_time');

            return [(string) $scheduleId, $dateTime ? Carbon::parse($dateTime)->toDateString() : now()->toDateString()];
        }

        return [null, now()->toDateString()];
    }

    /**
     * Grava (idempotente) a documentação do laudo no prontuário informado.
     */
    private function writeRunDocumentation(AiRun $aiRun, MedicalRecord $record, string $finalOutput): void
    {
        $doctorId = $this->resolveDoctorIdForCurrentUser((string) $aiRun->entity_id)
            ?? (string) $record->doctor_id;

        $title = __('ai.documentation.auto_title', [
            'workflow' => __('ai.workflow_' . $aiRun->workflow),
        ]);

        // O conteúdo aprovado pode carregar HTML/trechos injetados. Sanitiza com o
        // mesmo profile "medical" usado no fluxo manual antes de persistir.
        $sanitizedOutput = Purifier::clean($finalOutput, 'medical');

        MedicalRecordDocumentation::query()->updateOrCreate(
            ['ai_run_id' => $aiRun->id],
            [
                'medical_record_id' => $record->id,
                'patient_id'        => $record->patient_id,
                'doctor_id'         => $doctorId,
                'type'              => DocumentationType::Report->value,
                'title'             => $title,
                'content'           => $sanitizedOutput,
            ],
        );
    }

    /**
     * Paciente do run: o próprio patient_id ou, em runs de imagem sem paciente
     * explícito, o paciente dos exames analisados.
     */
    private function resolveRunPatientId(AiRun $aiRun): ?string
    {
        if (! empty($aiRun->patient_id)) {
            return (string) $aiRun->patient_id;
        }

        $patientId = PatientExam::query()
            ->whereIn('id', (array) ($aiRun->input_summary['exam_ids'] ?? []))
            ->value('patient_id');

        return $patientId !== null ? (string) $patientId : null;
    }

    /**
     * Resolve o Doctor.id associado ao usuário autenticado dentro da entity ativa.
     * Retorna null se o aprovador for admin/secretary sem perfil médico vinculado
     * — nesse caso o caller usa fallback (doctor do prontuário).
     */
    private function resolveDoctorIdForCurrentUser(string $entityId): ?string
    {
        $userId = (string) auth()->id();

        return Doctor::query()
            ->whereHas('entityUser', function ($query) use ($entityId, $userId): void {
                $query->where('entity_id', $entityId)->where('user_id', $userId);
            })
            ->value('id');
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

    private function selectedEntityId(): string
    {
        return (string) session('selected_entity_id');
    }

    /**
     * Retorna a cota mensal de créditos de IA do plano ativo da entity.
     * Lê o feature `ai_monthly_credits` da subscription ativa. Retorna 0 se sem
     * subscription ou se a feature não estiver definida (entity ainda pode
     * consumir créditos avulsos comprados).
     *
     * Schema de `plan_features`: id, plan_id, feature, value, ...
     */
    private function planQuotaForEntity(string $entityId): int
    {
        $subscription = Subscription::where('entity_id', $entityId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('created_at')
            ->first();

        if (! $subscription || ! $subscription->plan_id) {
            return 0;
        }

        $quota = DB::table('plan_features')
            ->where('plan_id', $subscription->plan_id)
            ->where('feature', 'ai_monthly_credits')
            ->value('value');

        return (int) ($quota ?? 0);
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

    private function assertRunBelongsToEntity(AiRun $aiRun, string $entityId): void
    {
        abort_if((string) $aiRun->entity_id !== $entityId, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, string $entityId): array
    {
        $canConsensus = $this->canConsensusForEntity($entityId);

        $validated = $request->validate([
            'workflow'          => ['required', 'string', 'in:exam_assistant,report_drafting,consensus_review,eye_image_analysis'],
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
        ]);

        // ── Eye Image: análise de imagem ocular ──────────────────────────────
        // Requer exames, resolve a propriedade por entidade, força o system
        // prompt clínico (server-side) e marca o nº de imagens para a estimativa.
        // O base64 NÃO é montado aqui nem guardado no run — é reconstruído em
        // tempo de execução a partir de exam_ids (ver AiRunExecutionService).
        if (($validated['workflow'] ?? '') === 'eye_image_analysis') {
            $validated['exam_ids']      = $this->authorizeExamIds((array) ($validated['exam_ids'] ?? []), $entityId);
            $validated['system_prompt'] = __('ai.eye_image_system_prompt');
            $validated['attachments']   = [];
            $validated['_image_count']  = count($validated['exam_ids']);
        }

        $usingConsensus = ($validated['mode'] ?? '') === AiRunMode::Consensus->value
            || ($validated['workflow'] ?? '') === 'consensus_review';

        if (($validated['workflow'] ?? '') === 'consensus_review') {
            $validated['mode'] = AiRunMode::Consensus->value;
            $usingConsensus    = true;
        }

        if ($usingConsensus && ! (bool) config('ai.enable_consensus', true)) {
            abort(422, __('ai.consensus_disabled'));
        }

        if ($usingConsensus && ! $canConsensus) {
            abort(403, __('ai.feature_consensus_unavailable'));
        }

        if (! in_array((string) $validated['mode'], $this->availableModes($canConsensus), true)) {
            abort(422, __('ai.mode_unavailable'));
        }

        $this->validateFeatureByWorkflow((string) $validated['workflow'], $entityId);
        $this->validateContextOwnership($validated, $entityId);

        $validated['context'] = $this->enrichContext($validated);

        $guarded                           = $this->promptGuardrails->sanitizePayload($validated);
        $guarded['payload']['_guardrails'] = $guarded['guardrails'];

        return $guarded['payload'];
    }

    /**
     * Constrói o contexto clínico server-side a partir de patient_id/medical_record_id
     * usando o AiMedicalContextBuilder (minimização + anonimização por iniciais).
     *
     * Em caso de colisão de chaves, o contexto server-side tem prioridade — o
     * usuário não pode sobrescrever os dados controlados.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function enrichContext(array $payload): array
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

        // Server context tem prioridade na colisão (anonimização + minimização).
        // Para auditoria, marca origem do contexto.
        return array_merge($userContext, $serverContext, [
            '_built_by' => 'AiMedicalContextBuilder',
        ]);
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
     * Resolve e autoriza os exames de imagem por entidade (PatientExam é escopado
     * via patient.entity_id). Exige ao menos um exame e bloqueia IDs de outra
     * entidade (cross-tenant). Devolve a lista de IDs válidos.
     *
     * @param array<int, string> $examIds
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

        // Algum exame não pertence à entidade ativa.
        abort_if(count($owned) !== count($examIds), 403);

        return $owned;
    }

    private function validateFeatureByWorkflow(string $workflow, string $entityId): void
    {
        if ($workflow === 'exam_assistant' && ! $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant)) {
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
    private function estimateCreditsForPayload(array $payload): AiCreditEstimateData
    {
        $mode          = AiRunMode::from((string) $payload['mode']);
        $workflow      = (string) $payload['workflow'];
        $providerCodes = $this->providerCodesForMode($mode);

        $prompt      = (string) ($payload['system_prompt'] ?? '') . "\n\n" . (string) $payload['user_prompt'];
        $context     = $payload['context'] ?? [];
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $chars       = mb_strlen($prompt) + mb_strlen($contextJson !== false ? $contextJson : '');

        $baseInput = max(120, (int) ceil($chars / 4));

        // Sobretaxa de tokens por imagem (visão) — reflete o custo das imagens
        // do módulo Eye Image na estimativa de créditos.
        $imageCount = (int) ($payload['_image_count'] ?? 0);
        $baseInput += $imageCount * (int) config('ai.eye_image.tokens_per_image', 300);

        $baseOutput = max(100, min((int) ($payload['max_output_tokens'] ?? 700), intdiv($baseInput, 2) + 120));

        $providerEstimates = [];

        foreach ($providerCodes as $index => $providerCode) {
            $factor          = 1 + ($index * 0.35);
            $inputTokens     = (int) ceil($baseInput * $factor);
            $outputTokens    = (int) ceil($baseOutput * ($index === 0 ? 1.0 : 0.85));
            $reasoningTokens = match ($mode) {
                AiRunMode::Economy   => 12,
                AiRunMode::Validated => 36 + ($index * 8),
                AiRunMode::Consensus => 72 + ($index * 10),
            };

            $providerEstimates[] = [
                'provider'         => AiProvider::from($providerCode),
                'model'            => $this->providerModel($providerCode),
                'input_tokens'     => $inputTokens,
                'output_tokens'    => $outputTokens,
                'reasoning_tokens' => $reasoningTokens,
                'tool_calls_count' => 0,
            ];
        }

        try {
            return $this->pricingService->estimateCredits(
                workflow: $workflow,
                mode: $mode,
                providerEstimates: $providerEstimates,
            );
        } catch (AiModelPriceNotFoundException) {
            $minimumBase = max(
                (int) config('ai.pricing.minimum_credits_default', 5),
                (int) data_get(config('ai.pricing.minimum_credits_by_workflow', []), $workflow, 0),
            );

            $multiplier = match ($mode) {
                AiRunMode::Economy   => 1,
                AiRunMode::Validated => 2,
                AiRunMode::Consensus => 3,
            };

            $minimum = $minimumBase * $multiplier;

            return new AiCreditEstimateData(
                workflow: $workflow,
                mode: $mode,
                rawCostUsd: 0.0,
                costUsdWithMargin: 0.0,
                marginMultiplier: (float) config('ai.pricing.margin_multiplier', 2.0),
                usdPerCredit: (float) config('ai.pricing.usd_per_credit', 0.01),
                creditsBeforeMinimum: 0,
                minimumCredits: $minimum,
                minimumApplied: true,
                normalizedCredits: $minimum,
                breakdown: [[
                    'source' => 'fallback_minimum_only',
                    'reason' => 'model_price_not_configured',
                ]],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function providerCodesForMode(AiRunMode $mode): array
    {
        return $this->providerSettings->providerCodesForMode($mode);
    }

    private function providerModel(string $provider): string
    {
        return match ($provider) {
            'openai'    => (string) config('ai.providers.openai.model', 'gpt-5-mini'),
            'anthropic' => (string) config('ai.providers.anthropic.model', 'claude-sonnet-4-5'),
            'gemini'    => (string) config('ai.providers.gemini.model', 'gemini-2.0-flash'),
            default     => 'unknown-model',
        };
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
     * @param array<string, mixed> $guardrails
     *
     * @return list<string>
     */
    private function guardrailSafetyNotes(array $guardrails): array
    {
        if (! (bool) ($guardrails['pii_redacted'] ?? false)) {
            return [];
        }

        return [__('ai.safety.pii_redacted')];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicEstimate(AiCreditEstimateData $estimate): array
    {
        return [
            'workflow'           => $estimate->workflow,
            'mode'               => $estimate->mode->value,
            'minimum_applied'    => $estimate->minimumApplied,
            'minimum_credits'    => $estimate->minimumCredits,
            'normalized_credits' => $estimate->normalizedCredits,
        ];
    }

    /**
     * @return array<int, array{id:string,name:string,code:string}>
     */
    private function patientsForEntity(string $entityId): array
    {
        return Patient::query()
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where('patients.entity_id', $entityId)
            ->orderBy('people.full_name')
            ->limit(150)
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
     * @return array<int, array{id:string,code:string,patient_id:string,patient_name:string,created_at:string|null}>
     */
    private function medicalRecordsForEntity(string $entityId): array
    {
        return MedicalRecord::query()
            ->with('patient.person:id,full_name')
            ->where('entity_id', $entityId)
            ->orderByDesc('created_at')
            ->limit(120)
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
