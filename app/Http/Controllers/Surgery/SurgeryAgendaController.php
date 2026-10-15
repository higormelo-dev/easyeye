<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Enums\Surgery\{ChecklistPhase, SurgicalCaseStatus};
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Models\Entity;
use App\Models\Surgery\SurgeryFacility;
use App\Models\Surgery\{SurgeryRoom, SurgicalCase, SurgicalChecklistPhase, SurgicalTeamMember};
use App\Services\Surgery\SurgicalCaseView;
use App\Support\CurrentDoctor;
use App\Support\Surgery\SurgeryLang;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\{Collection, Str};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Agenda cirúrgica: visão do dia (colunas = salas) ou da semana (colunas =
 * dias), filtros de local e cirurgião, fila de casos autorizados para agendar e
 * o mapa cirúrgico do dia em PDF. O agendamento em si é do
 * SurgeryScheduleController; esta tela só lê.
 *
 * O médico vê só as cirurgias em que opera (cirurgião ou equipe); perfil médico
 * sem cadastro de médico não vê nada.
 */
class SurgeryAgendaController extends Controller
{
    use AuthorizesSurgery;

    public function index(Request $request): InertiaResponse
    {
        $entityId      = (string) session('selected_entity_id');
        $doctorMissing = CurrentDoctor::isMissing();
        $view          = $request->query('view') === 'week' ? 'week' : 'day';
        $date          = $this->date($request);
        [$from, $to]   = $view === 'week'
            ? [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->startOfWeek(Carbon::MONDAY)->addDays(7)]
            : [$date->copy()->startOfDay(), $date->copy()->startOfDay()->addDay()];

        $facility = Str::isUuid((string) $request->query('facility')) ? (string) $request->query('facility') : null;
        $surgeon  = Str::isUuid((string) $request->query('surgeon')) ? (string) $request->query('surgeon') : null;

        $cases = $doctorMissing ? collect() : $this->windowCases($entityId, $from, $to, $facility, $surgeon);
        $queue = $doctorMissing ? collect() : $this->queue($entityId, $facility, $surgeon);

        $options = SurgicalCaseView::formOptions($entityId);
        $rooms   = $this->rooms($entityId, $facility, $cases);

        return Inertia::render('Panel/Surgery/Agenda', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('surgery.title'), 'url' => route('panel.surgery.index'), 'active' => false],
                ['label' => __('surgery_schedule.agenda.title'), 'url' => '#', 'active' => true],
            ],
            'view'    => $view,
            'date'    => $date->toDateString(),
            'range'   => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()],
            'filters' => ['facility' => $facility, 'surgeon' => $surgeon],
            'cases'   => $this->rows($cases),
            'queue'   => $this->queueRows($queue),
            'rooms'   => $rooms,
            'hours'   => ['start' => 6, 'end' => 22],
            'options' => [
                'facilities' => $options['facilities'],
                'surgeons'   => $options['surgeons'],
            ],
            'can'               => $this->surgeryAbilities(),
            'doctorMissing'     => $doctorMissing,
            'doctorMissingText' => CurrentDoctor::missingMessage(),
            'urls'              => [
                'agenda'         => route('panel.surgery.agenda'),
                'map'            => route('panel.surgery.agenda.map'),
                'index'          => route('panel.surgery.index'),
                'show'           => route('panel.surgery.show', ['surgicalCase' => '__ID__']),
                'schedule'       => route('panel.surgery.schedule.store', ['surgicalCase' => '__ID__']),
                'schedule_check' => route('panel.surgery.schedule.check', ['surgicalCase' => '__ID__']),
                'facilities'     => route('panel.setting.surgery-facilities.index'),
            ],
            't' => SurgeryLang::all(),
        ]);
    }

    /** Mapa cirúrgico do dia (PDF): por sala, em ordem de horário. */
    public function map(Request $request): Response
    {
        $entityId = (string) session('selected_entity_id');

        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());

        $request->validate([
            'date'     => ['required', 'date_format:Y-m-d'],
            'facility' => ['nullable', 'uuid'],
        ]);

        $date     = Carbon::createFromFormat('Y-m-d', (string) $request->query('date'))->startOfDay();
        $facility = (string) $request->query('facility') ?: null;
        $cases    = $this->windowCases($entityId, $date, $date->copy()->addDay(), $facility, null)
            ->load(['team', 'iolPlans']);

        $groups = $cases->groupBy(fn (SurgicalCase $c) => $c->room?->name ?? '')->sortKeys();

        // O mapa imprime nome, idade e CID de cada paciente do dia: um registro de acesso por caso.
        $cases->each(fn (SurgicalCase $c) => $this->logSurgeryRead($c, (string) $c->patient_id));

        return SnappyPdf::loadView('pdf.surgery_day_map', [
            'entity'      => Entity::query()->findOrFail($entityId),
            'date'        => $date,
            'groups'      => $groups,
            'total'       => $cases->count(),
            'facility'    => $facility ? SurgeryFacility::query()->where('entity_id', $entityId)->find($facility) : null,
            'generatedAt' => now(),
        ])
            ->setPaper('a4')
            ->setOrientation('landscape')
            ->setOption('margin-top', '1.2cm')
            ->setOption('margin-bottom', '1.5cm')
            ->setOption('margin-left', '1.2cm')
            ->setOption('margin-right', '1.2cm')
            ->setOption('footer-font-size', '7')
            ->setOption('footer-right', __('pdf.page_footer_native'))
            ->setOption('encoding', 'UTF-8')
            ->inline('mapa-cirurgico-' . $date->format('Y-m-d') . '.pdf');
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function date(Request $request): Carbon
    {
        $raw = (string) $request->query('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4))
            ? Carbon::createFromFormat('Y-m-d', $raw)->startOfDay()
            : now()->startOfDay();
    }

    /** Casos da clínica visíveis ao usuário (médico: só os em que opera). */
    private function scoped(string $entityId): Builder
    {
        $query = SurgicalCase::query()->where('surgical_cases.entity_id', $entityId);
        $own   = CurrentDoctor::doctor();

        if ($own !== null) {
            $query->where(function ($q) use ($own) {
                $q->where('surgeon_doctor_id', $own->id)
                    ->orWhereIn('surgical_cases.id', SurgicalTeamMember::query()->where('doctor_id', $own->id)->select('surgical_case_id'));
            });
        }

        return $query;
    }

    /**
     * Cirurgias com janela no período (agendadas e já realizadas no horário).
     *
     * @return Collection<int, SurgicalCase>
     */
    private function windowCases(string $entityId, Carbon $from, Carbon $to, ?string $facility, ?string $surgeon): Collection
    {
        return $this->scoped($entityId)
            ->occupyingWindow()
            ->where('scheduled_start', '<', $to)
            ->where('scheduled_end', '>', $from)
            ->when($facility, fn ($q) => $q->where('surgery_facility_id', $facility))
            ->when($surgeon, fn ($q) => $q->where(function ($inner) use ($surgeon) {
                $inner->where('surgeon_doctor_id', $surgeon)
                    ->orWhereIn('surgical_cases.id', SurgicalTeamMember::query()->where('doctor_id', $surgeon)->select('surgical_case_id'));
            }))
            ->with(SurgicalCaseView::WITH)
            ->orderBy('scheduled_start')
            ->get();
    }

    /**
     * Autorizados e prontos para agendar (autorização que permite agendar e não
     * vencida), urgentes primeiro, depois os mais antigos.
     *
     * @return Collection<int, SurgicalCase>
     */
    private function queue(string $entityId, ?string $facility, ?string $surgeon): Collection
    {
        return $this->scoped($entityId)
            ->whereIn('status', [SurgicalCaseStatus::Authorized->value, SurgicalCaseStatus::PartiallyAuthorized->value])
            ->whereIn('authorization_status', ['authorized', 'partially_authorized', 'waived'])
            ->where(fn ($q) => $q->whereNull('authorization_valid_until')->orWhere('authorization_valid_until', '>=', now()->toDateString()))
            ->when($facility, fn ($q) => $q->where(fn ($inner) => $inner->where('surgery_facility_id', $facility)->orWhereNull('surgery_facility_id')))
            ->when($surgeon, fn ($q) => $q->where('surgeon_doctor_id', $surgeon))
            ->with(SurgicalCaseView::WITH)
            ->orderByRaw("case when urgency = 'urgent' then 0 else 1 end")
            ->orderBy('authorized_at')
            ->limit(60)
            ->get();
    }

    /**
     * Colunas do dia: salas ativas do local filtrado (ou de todos) + salas dos
     * casos exibidos (mesmo inativas) — nenhuma cirurgia some por a sala ter
     * sido desativada depois.
     *
     * @param Collection<int, SurgicalCase> $cases
     *
     * @return list<array<string, mixed>>
     */
    private function rooms(string $entityId, ?string $facility, Collection $cases): array
    {
        $usedIds = $cases->pluck('surgery_room_id')->filter()->unique()->all();

        return SurgeryRoom::query()
            ->where('entity_id', $entityId)
            ->with('facility:id,name')
            ->where(function ($q) use ($facility, $usedIds) {
                $q->where(fn ($active) => $active->where('active', true)->when($facility, fn ($f) => $f->where('surgery_facility_id', $facility)))
                    ->orWhereIn('id', $usedIds);
            })
            ->orderBy('name')
            ->get()
            ->map(fn (SurgeryRoom $r) => [
                'id'          => (string) $r->getKey(),
                'name'        => $r->name,
                'facility_id' => (string) $r->surgery_facility_id,
                'facility'    => $r->facility?->name,
                'active'      => (bool) $r->active,
            ])->all();
    }

    /**
     * @param Collection<int, SurgicalCase> $cases
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $cases): array
    {
        $closed = $this->closedPhases($cases->modelKeys());

        return $cases->map(fn (SurgicalCase $c) => [
            ...SurgicalCaseView::row($c),
            'room_id'     => $c->surgery_room_id,
            'facility_id' => $c->surgery_facility_id,
            'checklist'   => array_map(fn (string $phase) => in_array($phase, $closed[(string) $c->getKey()] ?? [], true), ChecklistPhase::values()),
        ])->values()->all();
    }

    /**
     * @param Collection<int, SurgicalCase> $queue
     *
     * @return list<array<string, mixed>>
     */
    private function queueRows(Collection $queue): array
    {
        return $queue->map(fn (SurgicalCase $c) => [
            ...SurgicalCaseView::row($c),
            'facility_id' => $c->surgery_facility_id,
            'duration'    => (int) ($c->estimated_duration_minutes ?: config('surgery.default_duration', 60)),
            'valid_until' => $c->authorization_valid_until?->toDateString(),
        ])->values()->all();
    }

    /**
     * @param list<string> $caseIds
     *
     * @return array<string, list<string>> caso => fases fechadas
     */
    private function closedPhases(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        return SurgicalChecklistPhase::query()
            ->whereIn('surgical_case_id', $caseIds)
            ->whereNotNull('closed_at')
            ->get(['surgical_case_id', 'phase'])
            ->groupBy('surgical_case_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => $r->phase->value)->all())
            ->all();
    }
}
