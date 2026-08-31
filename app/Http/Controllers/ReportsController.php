<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{ClientRule, EntityGate, ScheduleSituation};
use App\Models\{Covenant, Doctor, Entity, Schedule};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

class ReportsController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize(EntityGate::ViewFinancial->value, Entity::findOrFail(session('selected_entity_id')));

        return Inertia::render('Panel/Reports/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.reports'), 'url' => route('panel.reports.index'), 'active' => true],
            ],
            'links' => [
                ['title' => __('actions.reports.schedules_label'), 'icon' => 'ti-calendar', 'url' => route('panel.reports.schedules')],
                ['title' => __('actions.reports.absenteeism_label'), 'icon' => 'ti-user-x', 'url' => route('panel.reports.absenteeism')],
            ],
        ]);
    }

    public function schedules(Request $request): InertiaResponse
    {
        Gate::authorize(EntityGate::ViewFinancial->value, Entity::findOrFail(session('selected_entity_id')));

        $entityId  = session('selected_entity_id');
        $doctors   = $this->doctorsByEntity((string) $entityId);
        $covenants = Covenant::where(function ($q) use ($entityId) {
            $q->where('entity_id', $entityId)->orWhereNull('entity_id');
        })->where('active', true)->orderBy('name')->get(['id', 'name']);
        $situations = collect(ScheduleSituation::cases())
            ->map(fn (ScheduleSituation $s) => ['value' => $s->value, 'label' => $s->label()])
            ->all();

        $payload = [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.reports'), 'url' => route('panel.reports.index'), 'active' => false],
                ['label' => __('actions.reports.schedules_label'), 'url' => '#', 'active' => true],
            ],
            'doctors'    => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
            'covenants'  => $covenants,
            'situations' => $situations,
            'filters'    => $request->only(['date_from', 'date_until', 'doctor_id', 'covenant_id', 'situation']),
            'schedules'  => [],
            'summary'    => null,
            'byDoctor'   => [],
        ];

        if (! $request->filled('date_from')) {
            return Inertia::render('Panel/Reports/Schedules', $payload);
        }

        $request->validate([
            'date_from'   => ['required', 'date'],
            'date_until'  => ['required', 'date', 'after_or_equal:date_from'],
            'doctor_id'   => ['nullable', 'uuid'],
            'covenant_id' => ['nullable', 'uuid'],
            'situation'   => ['nullable', 'integer'],
        ]);

        $dateFrom  = Carbon::parse($request->input('date_from'))->startOfDay();
        $dateUntil = Carbon::parse($request->input('date_until'))->endOfDay();

        $query = Schedule::with(['doctor', 'covenant', 'visitType', 'patient.person'])
            ->where('entity_id', $entityId)
            ->whereBetween('date_time', [$dateFrom, $dateUntil])
            ->whereNull('deleted_at');

        if ($doctorId = $request->input('doctor_id')) {
            $query->where('doctor_id', $doctorId);
        }

        if ($covenantId = $request->input('covenant_id')) {
            $query->where('covenant_id', $covenantId);
        }

        if ($situation = $request->integer('situation')) {
            $query->where('situation', $situation);
        }

        $loggedDoctor = $this->loggedDoctor();

        if ($loggedDoctor) {
            $query->where('doctor_id', $loggedDoctor->id);
        }

        $schedules = $query->orderBy('date_time')->get();

        $summary = [
            'total'     => $schedules->count(),
            'attended'  => $schedules->where('situation', ScheduleSituation::Attended)->count(),
            'cancelled' => $schedules->where('situation', ScheduleSituation::Cancelled)->count(),
            'noshow'    => $schedules->where('situation', ScheduleSituation::NoShow)->count(),
            'pending'   => $schedules->filter(fn ($s) => ! $s->situation->isTerminal())->count(),
        ];
        $summary['attendance_rate'] = $summary['total'] > 0
            ? round(($summary['attended'] / $summary['total']) * 100, 1)
            : 0;

        $byDoctor = $schedules->groupBy('doctor_id')->map(fn ($group) => [
            'doctor_name' => $group->first()->doctor?->user_name ?? 'Sem médico',
            'total'       => $group->count(),
            'attended'    => $group->where('situation', ScheduleSituation::Attended)->count(),
            'noshow'      => $group->where('situation', ScheduleSituation::NoShow)->count(),
            'cancelled'   => $group->where('situation', ScheduleSituation::Cancelled)->count(),
        ])->values()->all();

        return Inertia::render('Panel/Reports/Schedules', array_merge($payload, [
            'schedules' => $schedules->map(fn (Schedule $s) => [
                'date_time'       => $s->date_time?->format('d/m/Y H:i'),
                'patient_name'    => $s->patient?->person?->full_name,
                'doctor_name'     => $s->doctor?->user_name,
                'covenant'        => $s->covenant?->name,
                'visit_type'      => $s->visitType?->name,
                'situation'       => $s->situation->value,
                'situation_label' => $s->situation->label(),
            ]),
            'summary'  => $summary,
            'byDoctor' => $byDoctor,
        ]));
    }

    public function absenteeism(Request $request): InertiaResponse
    {
        Gate::authorize(EntityGate::ViewFinancial->value, Entity::findOrFail(session('selected_entity_id')));

        $entityId = session('selected_entity_id');
        $doctors  = $this->doctorsByEntity((string) $entityId);

        $payload = [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.reports'), 'url' => route('panel.reports.index'), 'active' => false],
                ['label' => __('actions.reports.absenteeism_label'), 'url' => '#', 'active' => true],
            ],
            'doctors'   => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
            'filters'   => $request->only(['date_from', 'date_until', 'doctor_id']),
            'schedules' => [],
            'summary'   => null,
        ];

        if (! $request->filled('date_from')) {
            return Inertia::render('Panel/Reports/Absenteeism', $payload);
        }

        $request->validate([
            'date_from'  => ['required', 'date'],
            'date_until' => ['required', 'date', 'after_or_equal:date_from'],
            'doctor_id'  => ['nullable', 'uuid'],
        ]);

        $dateFrom  = Carbon::parse($request->input('date_from'))->startOfDay();
        $dateUntil = Carbon::parse($request->input('date_until'))->endOfDay();

        $query = Schedule::with(['doctor', 'covenant', 'patient.person'])
            ->where('entity_id', $entityId)
            ->whereBetween('date_time', [$dateFrom, $dateUntil])
            ->whereIn('situation', [
                ScheduleSituation::NoShow->value,
                ScheduleSituation::Cancelled->value,
            ])
            ->whereNull('deleted_at');

        if ($doctorId = $request->input('doctor_id')) {
            $query->where('doctor_id', $doctorId);
        }
        $loggedDoctor = $this->loggedDoctor();

        if ($loggedDoctor) {
            $query->where('doctor_id', $loggedDoctor->id);
        }

        $schedules = $query->orderBy('date_time')->get();

        $totalInPeriod = Schedule::where('entity_id', $entityId)
            ->whereBetween('date_time', [$dateFrom, $dateUntil])
            ->whereNull('deleted_at')
            ->when($request->input('doctor_id'), fn ($q) => $q->where('doctor_id', $request->input('doctor_id')))
            ->count();

        $summary = [
            'total_absent'     => $schedules->count(),
            'noshow'           => $schedules->where('situation', ScheduleSituation::NoShow)->count(),
            'cancelled'        => $schedules->where('situation', ScheduleSituation::Cancelled)->count(),
            'total_period'     => $totalInPeriod,
            'absenteeism_rate' => $totalInPeriod > 0
                ? round(($schedules->count() / $totalInPeriod) * 100, 1)
                : 0,
        ];

        return Inertia::render('Panel/Reports/Absenteeism', array_merge($payload, [
            'schedules' => $schedules->map(fn (Schedule $s) => [
                'date_time'       => $s->date_time?->format('d/m/Y H:i'),
                'patient_name'    => $s->patient?->person?->full_name,
                'doctor_name'     => $s->doctor?->user_name,
                'covenant'        => $s->covenant?->name,
                'situation'       => $s->situation->value,
                'situation_label' => $s->situation->label(),
            ]),
            'summary' => $summary,
        ]));
    }

    private function loggedDoctor(): ?Doctor
    {
        if (session('user_rule') !== ClientRule::Doctor->value) {
            return null;
        }

        return Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('doctors.entity_user_id', session('selected_entity_user_id'))
            ->select('doctors.*')
            ->first();
    }

    private function doctorsByEntity(string $entityId)
    {
        return Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('entity_users.entity_id', $entityId)
            ->select('doctors.*', 'users.name as user_name', 'users.id as user_id')
            ->orderBy('users.name')
            ->get();
    }
}
