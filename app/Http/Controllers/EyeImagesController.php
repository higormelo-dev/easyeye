<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Models\{Doctor, Entity, Patient, PatientExam};
use App\Services\FeatureGateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Storage;
use Inertia\{Inertia, Response as InertiaResponse};

class EyeImagesController extends Controller
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId = session('selected_entity_id');
        $entityId = (string) $entityId;

        $doctors = Doctor::with('person')
            ->whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->get(['id', 'person_id']);

        $patients = $this->queryPatients(entityId: $entityId, period: 'hoje');
        $entity   = Entity::find($entityId, ['id', 'name', 'address', 'telephone', 'cellphone', 'email', 'logo']);
        $hasExamAssistant = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $canConsensus = (bool) config('ai.enable_consensus', true)
            && $this->featureGate->can($entityId, FeatureKey::HasAiConsensus);

        return Inertia::render('Panel/EyeImages/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'),    'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('dashboard.module_eye_images'),   'url' => '#',                     'active' => true],
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
                'search'        => route('panel.eye-images.search'),
                'patient_urls'  => route('panel.eye-images.patient-urls', ['patient' => '__ID__']),
                'image_url'     => route('panel.eye-images.image-url',    ['exam'    => '__ID__']),
            ],
            'ai' => [
                'enabled'   => $hasExamAssistant || $canConsensus,
                'workflows' => array_values(array_filter([
                    $hasExamAssistant ? 'exam_assistant' : null,
                    $canConsensus ? 'consensus_review' : null,
                ])),
                'can_consensus' => $canConsensus,
                'labels' => [
                    'title'                 => __('ai.title'),
                    'assistance_button'     => __('ai.assistance_button'),
                    'support_notice'        => __('ai.support_notice'),
                    'workflow'              => __('ai.workflow'),
                    'risk'                  => __('ai.risk'),
                    'patient_optional'      => __('ai.patient_optional'),
                    'select_placeholder'    => __('ai.select_placeholder'),
                    'system_prompt'         => __('ai.system_prompt'),
                    'system_prompt_default' => __('ai.system_prompt_default'),
                    'clinical_prompt'       => __('ai.clinical_prompt'),
                    'clinical_prompt_placeholder' => __('ai.clinical_prompt_placeholder'),
                    'prompt_min_chars'      => __('ai.prompt_min_chars', ['min' => 12]),
                    'estimated_credits'     => __('ai.estimated_credits'),
                    'raw_cost_usd'          => __('ai.raw_cost_usd'),
                    'estimate'              => __('ai.estimate'),
                    'run'                   => __('ai.run'),
                    'credits_available'     => __('ai.credits_available'),
                    'credits_reserved'      => __('ai.credits_reserved'),
                    'run_created_waiting_review' => __('ai.run_created_waiting_review'),
                    'estimate_failed'       => __('ai.estimate_failed'),
                    'estimate_network_error' => __('ai.estimate_network_error'),
                    'run_create_failed'     => __('ai.run_create_failed'),
                    'run_network_error'     => __('ai.run_network_error'),
                    'workflow_exam_assistant' => __('ai.workflow_exam_assistant'),
                    'workflow_consensus_review' => __('ai.workflow_consensus_review'),
                    'risk_low'              => __('ai.risk_low'),
                    'risk_medium'           => __('ai.risk_medium'),
                    'risk_high'             => __('ai.risk_high'),
                    'close'                 => __('actions.close'),
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
                'id'              => (string) $e->id,
                'exam_id'         => $e->exam_id !== null ? (string) $e->exam_id : null,
                'laterality'      => (int) ($e->laterality ?? 0),
                'active'          => (bool) $e->active,
                'archive'         => $e->archive,
                'has_archive'     => $e->archive !== null,
                'created_at'      => optional($e->created_at)->toIso8601String(),
                'created_at_fmt'  => $e->created_at?->format('d/m/Y H:i'),
                'entity_integrator_equipment_id' => $e->entity_integrator_equipment_id !== null
                    ? (string) $e->entity_integrator_equipment_id
                    : null,
                'exam_type'  => $e->examType
                    ? ['id' => (string) $e->examType->id, 'name' => $e->examType->name]
                    : null,
                'exam_type_name' => $e->examType?->name,
                'doctor'    => $e->doctor && $e->doctor->person
                    ? ['id' => (string) $e->doctor->id, 'name' => $e->doctor->person->full_name]
                    : null,
                'doctor_name'  => $e->doctor?->person?->full_name,
                'equipment' => $e->equipment
                    ? ['id' => (string) $e->equipment->id, 'name' => $e->equipment->name]
                    : null,
                'equipment_name' => $e->equipment?->name,
            ])->all(),
        ])->all();
    }

    private function queryPatients(
        string $entityId,
        string $period   = 'hoje',
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
                    $q->with(['examType', 'doctor.person', 'equipment'])
                        ->orderByDesc('created_at');
                },
            ])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();
    }
}
