<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Doctor, Schedule};
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index()
    {
        $meta = [
            'title'       => __('actions.sidemenu.reports'),
            'action'      => __('actions.sidemenu.dashboard'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.reports'), 'url' => route('panel.reports.index'), 'active' => true],
            ],
        ];

        return view('system.reports.index', compact('meta'));
    }

    /**
     * Production report: agendamentos grouped by situation, doctor, covenant.
     * Filters: date_from, date_until, doctor_id, covenant_id, situation
     */
    public function schedules(Request $request)
    {
        $entityId  = session('selected_entity_id');
        $doctors   = $this->doctorsByEntity($entityId);
        $covenants = Covenant::where(function ($q) use ($entityId) {
            $q->where('entity_id', $entityId)->orWhereNull('entity_id');
        })->where('active', true)->orderBy('name')->get();
        $situations = ScheduleSituation::cases();

        $meta = [
            'title'       => 'Relatórios',
            'action'      => 'Relatório de Produção',
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Relatórios', 'url' => route('panel.reports.index'), 'active' => false],
                ['label' => 'Produção', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        if (! $request->filled('date_from')) {
            return view('system.reports.schedules', compact(
                'doctors', 'covenants', 'situations', 'meta'
            ));
        }

        $request->validate([
            'date_from'   => ['required', 'date'],
            'date_until'  => ['required', 'date', 'after_or_equal:date_from'],
            'doctor_id'   => ['nullable', 'uuid'],
            'covenant_id' => ['nullable', 'uuid'],
            'situation'   => ['nullable', 'integer'],
        ]);

        $entityId  = session('selected_entity_id');
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

        // Summary totals
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

        // Grouped by doctor
        $byDoctor = $schedules->groupBy('doctor_id')->map(fn ($group) => [
            'doctor_name' => $group->first()->doctor?->user_name ?? 'Sem médico',
            'total'       => $group->count(),
            'attended'    => $group->where('situation', ScheduleSituation::Attended)->count(),
            'noshow'      => $group->where('situation', ScheduleSituation::NoShow)->count(),
            'cancelled'   => $group->where('situation', ScheduleSituation::Cancelled)->count(),
        ])->values();

        return view('system.reports.schedules', compact(
            'schedules', 'summary', 'byDoctor',
            'doctors', 'covenants', 'situations', 'meta'
        ));
    }

    /**
     * Absenteeism report: no-shows and cancellations with averages.
     */
    public function absenteeism(Request $request)
    {
        $entityId = session('selected_entity_id');
        $doctors  = $this->doctorsByEntity($entityId);

        $meta = [
            'title'       => 'Relatórios',
            'action'      => 'Relatório de Absenteísmo',
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Relatórios', 'url' => route('panel.reports.index'), 'active' => false],
                ['label' => 'Absenteísmo', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        if (! $request->filled('date_from')) {
            return view('system.reports.absenteeism', compact('doctors', 'meta'));
        }

        $request->validate([
            'date_from'  => ['required', 'date'],
            'date_until' => ['required', 'date', 'after_or_equal:date_from'],
            'doctor_id'  => ['nullable', 'uuid'],
        ]);

        $entityId  = session('selected_entity_id');
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

        return view('system.reports.absenteeism', compact(
            'schedules', 'summary', 'doctors', 'meta'
        ));
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
