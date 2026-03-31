<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Doctor, Schedule};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response as InertiaResponse};

class ReportsController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Reports/Index');
    }

    /**
     * Production report: agendamentos grouped by situation, doctor, covenant.
     * Filters: date_from, date_until, doctor_id, covenant_id, situation
     */
    public function schedules(Request $request): InertiaResponse
    {
        $entityId  = session('selected_entity_id');
        $doctors   = $this->doctorsByEntity($entityId);
        $covenants = Covenant::where(function ($q) use ($entityId) {
            $q->where('entity_id', $entityId)->orWhereNull('entity_id');
        })->where('active', true)->orderBy('name')->get();
        $situations = ScheduleSituation::cases();

        $filters = $request->only(['date_from', 'date_until', 'doctor_id', 'covenant_id', 'situation']);

        if (! $request->filled('date_from')) {
            return Inertia::render('Reports/Schedules', [
                'doctors'    => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
                'covenants'  => $covenants->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
                'situations' => collect($situations)->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
                'filters'    => $filters,
                'results'    => null,
            ]);
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

        $query = Schedule::with(['doctor.entityUser.user', 'covenant', 'patient.person'])
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
            'total'           => $schedules->count(),
            'attended'        => $schedules->where('situation', ScheduleSituation::Attended)->count(),
            'cancelled'       => $schedules->where('situation', ScheduleSituation::Cancelled)->count(),
            'noshow'          => $schedules->where('situation', ScheduleSituation::NoShow)->count(),
            'pending'         => $schedules->filter(fn ($s) => ! $s->situation->isTerminal())->count(),
            'attendance_rate' => 0,
        ];
        $summary['attendance_rate'] = $summary['total'] > 0
            ? round(($summary['attended'] / $summary['total']) * 100, 1)
            : 0;

        $byDoctor = $schedules->groupBy('doctor_id')->map(fn ($group) => [
            'doctor_name' => $group->first()->doctor?->entityUser?->user?->name ?? 'Sem médico',
            'total'       => $group->count(),
            'attended'    => $group->where('situation', ScheduleSituation::Attended)->count(),
            'noshow'      => $group->where('situation', ScheduleSituation::NoShow)->count(),
            'cancelled'   => $group->where('situation', ScheduleSituation::Cancelled)->count(),
        ])->values();

        $schedulesData = $schedules->map(fn ($s) => [
            'id'               => $s->id,
            'code'             => $s->code,
            'date_time'        => $s->date_time->format('d/m/Y H:i'),
            'patient_name'     => $s->patient?->person?->full_name ?? $s->full_name ?? '—',
            'doctor_name'      => $s->doctor?->entityUser?->user?->name ?? '—',
            'covenant_name'    => $s->covenant?->name ?? '—',
            'situation_label'  => $s->situation->label(),
            'situation_badge'  => $s->situation->badgeClass(),
        ])->values();

        return Inertia::render('Reports/Schedules', [
            'doctors'    => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
            'covenants'  => $covenants->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
            'situations' => collect($situations)->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'filters'    => $filters,
            'results'    => [
                'schedules' => $schedulesData,
                'summary'   => $summary,
                'by_doctor' => $byDoctor,
            ],
        ]);
    }

    /**
     * Absenteeism report: no-shows and cancellations with averages.
     */
    public function absenteeism(Request $request): InertiaResponse
    {
        $entityId = session('selected_entity_id');
        $doctors  = $this->doctorsByEntity($entityId);
        $filters  = $request->only(['date_from', 'date_until', 'doctor_id']);

        if (! $request->filled('date_from')) {
            return Inertia::render('Reports/Absenteeism', [
                'doctors' => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
                'filters' => $filters,
                'results' => null,
            ]);
        }

        $request->validate([
            'date_from'  => ['required', 'date'],
            'date_until' => ['required', 'date', 'after_or_equal:date_from'],
            'doctor_id'  => ['nullable', 'uuid'],
        ]);

        $dateFrom  = Carbon::parse($request->input('date_from'))->startOfDay();
        $dateUntil = Carbon::parse($request->input('date_until'))->endOfDay();

        $query = Schedule::with(['doctor.entityUser.user', 'patient.person'])
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

        $schedulesData = $schedules->map(fn ($s) => [
            'id'                  => $s->id,
            'code'                => $s->code,
            'date_time'           => $s->date_time->format('d/m/Y H:i'),
            'patient_name'        => $s->patient?->person?->full_name ?? $s->full_name ?? '—',
            'doctor_name'         => $s->doctor?->entityUser?->user?->name ?? '—',
            'situation_label'     => $s->situation->label(),
            'situation_badge'     => $s->situation->badgeClass(),
            'cancellation_reason' => $s->cancellation_reason ?? '—',
        ])->values();

        return Inertia::render('Reports/Absenteeism', [
            'doctors' => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
            'filters' => $filters,
            'results' => [
                'schedules' => $schedulesData,
                'summary'   => $summary,
            ],
        ]);
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
