<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Enums\DataAccessPurpose;
use App\Enums\Surgery\SurgicalCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\SurgicalCaseRequest;
use App\Models\{Patient, PatientExam};
use App\Models\Surgery\{SurgicalCase, SurgicalTeamMember};
use App\Services\Surgery\{SurgeryShowSections, SurgicalCaseService, SurgicalCaseView};
use App\Support\CurrentDoctor;
use App\Support\Surgery\SurgeryLang;
use App\Traits\LogsDataAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Casos cirúrgicos (Fase 4): lista, detalhe e cadastro do pedido. Etapas
 * seguintes (autorização, agenda, cirurgia, alta) têm controllers próprios e
 * entram como abas da tela do caso (config/surgery.php → show_sections).
 */
class SurgeryCasesController extends Controller
{
    use AuthorizesSurgery;
    use LogsDataAccess;

    /** Grupos de estado das abas da lista. */
    private const GROUPS = [
        'draft'      => ['draft'],
        'awaiting'   => ['awaiting_authorization'],
        'authorized' => ['authorized', 'partially_authorized'],
        'scheduled'  => ['scheduled'],
        'performed'  => ['performed', 'discharged'],
        'closed'     => ['denied', 'cancelled', 'suspended'],
    ];

    public function __construct(private readonly SurgicalCaseService $service)
    {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId      = (string) session('selected_entity_id');
        $doctorMissing = CurrentDoctor::isMissing();
        $group         = (string) $request->query('group', 'all');
        $search        = trim((string) $request->query('q', ''));
        $surgeon       = (string) $request->query('surgeon', '');

        $base = $this->scopedQuery($entityId, $doctorMissing);

        $counts = (clone $base)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $query = (clone $base)
            ->with(SurgicalCaseView::WITH)
            ->when(isset(self::GROUPS[$group]), fn ($q) => $q->whereIn('status', self::GROUPS[$group]))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->whereLikeUnaccent('surgical_cases.code', $search)
                        ->orWhereLikeUnaccent('surgical_cases.title', $search)
                        ->orWhereHas('patient.person', fn ($p) => $p->whereLikeUnaccent('full_name', $search));
                });
            })
            ->when(Str::isUuid($surgeon), fn ($q) => $q->where('surgeon_doctor_id', $surgeon))
            ->orderByRaw('scheduled_start desc nulls last')
            ->orderByDesc('created_at');

        $page   = $query->paginate(15)->withQueryString();
        $severe = SurgicalCaseView::severeCaseIds($page->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->all());

        return Inertia::render('Panel/Surgery/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('surgery.title'), 'url' => '#', 'active' => true],
            ],
            'cases'             => $page->through(fn (SurgicalCase $c) => SurgicalCaseView::row($c, $severe)),
            'counts'            => $this->groupCounts($counts->all()),
            'filters'           => ['group' => isset(self::GROUPS[$group]) ? $group : 'all', 'q' => $search, 'surgeon' => $surgeon],
            'options'           => fn () => SurgicalCaseView::formOptions($entityId),
            'can'               => $this->surgeryAbilities(),
            'doctorMissing'     => $doctorMissing,
            'doctorMissingText' => CurrentDoctor::missingMessage(),
            'urls'              => $this->urls(),
            't'                 => SurgeryLang::all(),
        ]);
    }

    public function show(SurgicalCase $surgicalCase): InertiaResponse
    {
        $this->authorizeCaseAccess($surgicalCase);

        // CFM 2.227/2018 + LGPD art. 37: abrir o caso é leitura de dado sensível do paciente.
        $this->logAccess(
            $surgicalCase,
            CurrentDoctor::isDoctorProfile() ? DataAccessPurpose::PatientCare : DataAccessPurpose::Administrative,
            patientId: (string) $surgicalCase->patient_id,
        );

        $surgicalCase->load(SurgicalCaseView::WITH);

        $can = $this->surgeryAbilities();

        ['tabs' => $tabs, 'sections' => $sections] = SurgeryShowSections::build($surgicalCase, $can);

        return Inertia::render('Panel/Surgery/Show', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('surgery.title'), 'url' => route('panel.surgery.index'), 'active' => false],
                ['label' => $surgicalCase->code, 'url' => '#', 'active' => true],
            ],
            'surgicalCase' => SurgicalCaseView::detail($surgicalCase),
            'tabs'         => $tabs,
            'sections'     => $sections,
            'options'      => fn () => SurgicalCaseView::formOptions((string) $surgicalCase->entity_id),
            'can'          => [
                ...$can,
                // O formulário abre sempre que algum grupo de campos ainda aceita alteração (o pedido trava, a equipe e a parte clínica não).
                'edit'    => $can['manage'] && $surgicalCase->status->allowsAnyChange(),
                'cancel'  => $can['manage'] && ! $surgicalCase->status->isFinal() && ! $surgicalCase->status->wasPerformed(),
                'suspend' => $can['manage'] && $surgicalCase->status->canTransitionTo(SurgicalCaseStatus::Suspended),
                'resume'  => $can['manage'] && $surgicalCase->status === SurgicalCaseStatus::Suspended,
                'delete'  => $can['manage'] && $surgicalCase->status === SurgicalCaseStatus::Draft && $surgicalCase->requested_at === null,
            ],
            'urls' => [
                ...$this->urls(),
                'show'          => route('panel.surgery.show', $surgicalCase),
                'update'        => route('panel.surgery.update', $surgicalCase),
                'destroy'       => route('panel.surgery.destroy', $surgicalCase),
                'cancel'        => route('panel.surgery.cancel', $surgicalCase),
                'suspend'       => route('panel.surgery.suspend', $surgicalCase),
                'resume'        => route('panel.surgery.resume', $surgicalCase),
                'authorization' => [
                    'request'  => route('panel.surgery.authorization.request', $surgicalCase),
                    'withdraw' => route('panel.surgery.authorization.withdraw', $surgicalCase),
                    'record'   => route('panel.surgery.authorization.record', $surgicalCase),
                ],
                'patient_exams' => route('panel.surgery.patient-exams', ['patient' => $surgicalCase->patient_id]),
            ],
            't' => SurgeryLang::all(),
        ]);
    }

    public function store(SurgicalCaseRequest $request): RedirectResponse
    {
        $this->authorizeManage();
        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());

        $case = $this->service->create(
            (string) session('selected_entity_id'),
            $request->caseData($this->surgeryAbilities()['perform']),
        );

        return redirect()->route('panel.surgery.show', $case)->with('success', __('surgery.messages.created'));
    }

    public function update(SurgicalCaseRequest $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseOwner($surgicalCase);

        $this->service->update(
            $surgicalCase,
            $request->caseData($this->surgeryAbilities()['perform']),
            $request->expectedRevision(),
        );

        return back()->with('success', __('surgery.messages.updated'));
    }

    public function destroy(SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseOwner($surgicalCase);

        $this->service->destroyDraft($surgicalCase);

        return redirect()->route('panel.surgery.index')->with('success', __('surgery.messages.deleted'));
    }

    /** Exames do paciente para vincular ao caso (biometria, topografia, OCT...). */
    public function patientExams(string $patient): JsonResponse
    {
        $this->authorizeManage();

        $patientModel = Patient::query()
            ->where('entity_id', (string) session('selected_entity_id'))
            ->whereKey($patient)
            ->firstOrFail();

        $exams = PatientExam::query()
            ->where('patient_id', $patientModel->getKey())
            ->with('examType:id,name')
            ->orderByDesc('exam_performed_at')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        return response()->json($exams->map(fn ($e) => [
            'id'           => (string) $e->getKey(),
            'name'         => $e->examType?->name,
            'performed_at' => $e->exam_performed_at?->toDateString(),
            'laterality'   => SurgicalCaseView::lateralityLabel($e->laterality),
        ]));
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** Casos da clínica; o médico enxerga só os dele (cirurgião ou equipe). */
    private function scopedQuery(string $entityId, bool $doctorMissing): Builder
    {
        $query = SurgicalCase::query()->where('surgical_cases.entity_id', $entityId);

        if ($doctorMissing) {
            return $query->whereRaw('1 = 0');
        }

        $own = CurrentDoctor::doctor();

        if ($own !== null) {
            $query->where(function ($q) use ($own) {
                $q->where('surgeon_doctor_id', $own->id)
                    ->orWhereIn('surgical_cases.id', SurgicalTeamMember::query()->where('doctor_id', $own->id)->select('surgical_case_id'));
            });
        }

        return $query;
    }

    /**
     * @param array<string, int|string> $byStatus
     *
     * @return array<string, int>
     */
    private function groupCounts(array $byStatus): array
    {
        $counts = ['all' => (int) array_sum($byStatus)];

        foreach (self::GROUPS as $group => $statuses) {
            $counts[$group] = (int) array_sum(array_map(fn ($s) => $byStatus[$s] ?? 0, $statuses));
        }

        return $counts;
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        return [
            'index'              => route('panel.surgery.index'),
            'store'              => route('panel.surgery.store'),
            'patient_search'     => route('panel.patients.search'),
            'procedure_search'   => route('panel.procedures.search'),
            'cid_search'         => route('panel.cid10.search'),
            'patient_exams_base' => route('panel.surgery.patient-exams', ['patient' => '__ID__']),
            'facilities'         => route('panel.setting.surgery-facilities.index'),
        ];
    }
}
