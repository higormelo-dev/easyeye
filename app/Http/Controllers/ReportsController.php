<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, EntityGate, ScheduleSituation};
use App\Models\{Covenant, Doctor, Entity, Schedule};
use App\Services\DataAccessLogService;
use App\Support\CurrentDoctor;
use App\Support\Export\SpreadsheetWriter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{Request, Response as HttpResponse};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response as InertiaResponse};

class ReportsController extends Controller
{
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
                // GAP fechado (revisão "relatório direto no módulo"): antes
                // apontava pra panel.reports.index (página-hub "Relatórios"
                // removida — ver docblock de App\Support\PanelNavigation).
                // Este relatório é 100% dado de Agenda; breadcrumb agora
                // reflete o menu real (filho de "Agendas").
                ['label' => __('actions.sidemenu.schedules'), 'url' => route('panel.schedules.index'), 'active' => false],
                ['label' => __('actions.reports.schedules_label'), 'url' => '#', 'active' => true],
            ],
            'doctors'           => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
            'covenants'         => $covenants,
            'situations'        => $situations,
            'filters'           => $request->only(['date_from', 'date_until', 'doctor_id', 'covenant_id', 'situation']),
            'schedules'         => [],
            'summary'           => null,
            'byDoctor'          => [],
            'doctorMissing'     => CurrentDoctor::isMissing(),
            'doctorMissingText' => CurrentDoctor::missingMessage(),
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

        $this->scopeToLoggedDoctor($query);

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
                ['label' => __('actions.sidemenu.schedules'), 'url' => route('panel.schedules.index'), 'active' => false],
                ['label' => __('actions.reports.absenteeism_label'), 'url' => '#', 'active' => true],
            ],
            'doctors'           => $doctors->map(fn ($d) => ['id' => $d->id, 'name' => $d->user_name]),
            'filters'           => $request->only(['date_from', 'date_until', 'doctor_id']),
            'schedules'         => [],
            'summary'           => null,
            'doctorMissing'     => CurrentDoctor::isMissing(),
            'doctorMissingText' => CurrentDoctor::missingMessage(),
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
        $this->scopeToLoggedDoctor($query);

        $schedules = $query->orderBy('date_time')->get();

        // Denominador com o MESMO recorte de médico da lista (antes ignorava o
        // médico logado e a taxa saía sobre a clínica inteira).
        $totalQuery = Schedule::where('entity_id', $entityId)
            ->whereBetween('date_time', [$dateFrom, $dateUntil])
            ->whereNull('deleted_at')
            ->when($request->input('doctor_id'), fn ($q) => $q->where('doctor_id', $request->input('doctor_id')));
        $this->scopeToLoggedDoctor($totalQuery);
        $totalInPeriod = $totalQuery->count();

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

    // ── Primeira consulta (Fase 1 / B3) ─────────────────────────────────────

    /** Período máximo do relatório (dias). */
    public const FIRST_VISITS_MAX_DAYS = 366;

    /**
     * Pacientes cuja PRIMEIRA consulta atendida na clínica cai no período:
     * data, paciente, médico, convênio e tipo. Filtro por médico; perfil
     * médico vê só as primeiras consultas que foram com ele (CurrentDoctor).
     * Sem período informado: o mês atual até hoje.
     */
    public function firstConsultations(Request $request): InertiaResponse
    {
        $entity                    = $this->authorizeFirstConsultations();
        [$from, $until, $doctorId] = $this->firstConsultationsFilters($request, (string) $entity->id);

        $doctorMissing = CurrentDoctor::isMissing();
        $isDoctor      = CurrentDoctor::isDoctorProfile();
        $schedules     = $doctorMissing ? collect() : $this->firstConsultationsQuery((string) $entity->id, $from, $until, $doctorId)->get();
        $locale        = app()->getLocale();

        return Inertia::render('Panel/Reports/FirstConsultations', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.schedules'), 'url' => route('panel.schedules.index'), 'active' => false],
                ['label' => __('first_consultations.title'), 'url' => '#', 'active' => true],
            ],
            'filters' => [
                'date_from'  => $from->toDateString(),
                'date_until' => $until->toDateString(),
                'doctor_id'  => $isDoctor ? null : $doctorId,
            ],
            'doctors' => $isDoctor ? [] : $this->doctorsByEntity((string) $entity->id)
                ->map(fn ($d) => ['id' => (string) $d->id, 'name' => $d->user_name])
                ->values(),
            'rows' => $schedules->map(fn (Schedule $s) => [
                'id'             => (string) $s->id,
                'date_time'      => $s->date_time->copy()->locale($locale)->isoFormat('L LT'),
                'patient_name'   => $s->patient?->person?->full_name ?? $s->full_name,
                'patient_code'   => $s->patient?->code,
                'doctor_name'    => $s->doctor?->entityUser?->user?->name ?? '—',
                'covenant'       => $s->covenant?->name,
                'type'           => $this->visitTypeLabel($s),
                'is_urgent_care' => $s->isUrgentCare(),
            ])->values(),
            'summary' => [
                'total'     => $schedules->count(),
                'by_doctor' => $schedules
                    ->groupBy('doctor_id')
                    ->map(fn ($group) => [
                        'name'  => $group->first()->doctor?->entityUser?->user?->name ?? '—',
                        'total' => $group->count(),
                    ])
                    ->sortByDesc('total')
                    ->values(),
            ],
            'period' => [
                'from'  => $from->copy()->locale($locale)->isoFormat('L'),
                'until' => $until->copy()->locale($locale)->isoFormat('L'),
            ],
            'isDoctor'          => $isDoctor,
            'doctorMissing'     => $doctorMissing,
            'doctorMissingText' => CurrentDoctor::missingMessage(),
            'urls'              => ['export' => route('panel.schedules.reports.first-consultations.export')],
            't'                 => trans('first_consultations'),
        ]);
    }

    /**
     * Exportação (CSV/XLSX, mesmo gerador dos relatórios financeiros) com o
     * mesmo recorte da tela. Leva nome de paciente: registro de acesso
     * (Export) por linha exportada.
     */
    public function exportFirstConsultations(Request $request, SpreadsheetWriter $sheets, DataAccessLogService $accessLog): HttpResponse
    {
        $entity = $this->authorizeFirstConsultations();
        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());

        [$from, $until, $doctorId] = $this->firstConsultationsFilters($request, (string) $entity->id);
        $schedules                 = $this->firstConsultationsQuery((string) $entity->id, $from, $until, $doctorId)->get();
        $format                    = in_array($request->query('format'), ['xlsx', 'csv'], true) ? (string) $request->query('format') : 'csv';

        $rows = [[
            __('first_consultations.col_date'),
            __('first_consultations.col_patient'),
            __('first_consultations.col_patient_code'),
            __('first_consultations.col_doctor'),
            __('first_consultations.col_covenant'),
            __('first_consultations.col_type'),
        ]];

        foreach ($schedules as $s) {
            $rows[] = [
                $s->date_time->format(__('first_consultations.datetime_format')),
                $s->patient?->person?->full_name ?? $s->full_name,
                $s->patient?->code,
                $s->doctor?->entityUser?->user?->name,
                $s->covenant?->name,
                $this->visitTypeLabel($s),
            ];
        }

        $accessLog->logMany($schedules, DataAccessPurpose::Export);

        $filename = 'primeiras_consultas_' . $from->toDateString() . '_' . $until->toDateString();

        if ($format === 'xlsx' && SpreadsheetWriter::supportsXlsx()) {
            return response($sheets->xlsx($rows, __('first_consultations.sheet_name')), 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.xlsx"',
            ]);
        }

        return response($sheets->csv($rows, (string) __('financial_reports.decimal_separator')), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
        ]);
    }

    /**
     * Admin/financeiro (mesmo Gate dos outros relatórios de agenda) e o
     * médico — este com o recorte dele (a rota já deixa os três passarem).
     */
    private function authorizeFirstConsultations(): Entity
    {
        $entity = Entity::findOrFail(session('selected_entity_id'));

        abort_unless(
            Gate::allows(EntityGate::ViewFinancial->value, $entity) || CurrentDoctor::isDoctorProfile(),
            403,
        );

        return $entity;
    }

    /**
     * Período (padrão: mês atual até hoje; teto FIRST_VISITS_MAX_DAYS) e o
     * médico do filtro — perfil médico: sempre ele (CurrentDoctor).
     *
     * @return array{0: Carbon, 1: Carbon, 2: ?string}
     */
    private function firstConsultationsFilters(Request $request, string $entityId): array
    {
        $validated = $request->validate([
            'date_from'  => ['nullable', 'date_format:Y-m-d'],
            'date_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'doctor_id'  => ['nullable', 'uuid'],
        ]);

        $from  = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : now()->startOfMonth();
        $until = isset($validated['date_until']) ? Carbon::parse($validated['date_until'])->endOfDay() : now()->endOfDay();

        if ($from->diffInDays($until) > self::FIRST_VISITS_MAX_DAYS) {
            throw ValidationException::withMessages([
                'date_until' => __('first_consultations.period_too_long', ['days' => self::FIRST_VISITS_MAX_DAYS]),
            ]);
        }

        if (CurrentDoctor::isDoctorProfile()) {
            return [$from, $until, CurrentDoctor::doctor()?->id ? (string) CurrentDoctor::doctor()->id : null];
        }

        $doctorId = $validated['doctor_id'] ?? null;
        $valid    = $doctorId && $this->doctorsByEntity($entityId)->contains('id', $doctorId);

        return [$from, $until, $valid ? (string) $doctorId : null];
    }

    /**
     * A 1ª consulta ATENDIDA de cada paciente na clínica (não existe outra
     * atendida antes dela — desempate pelo id no mesmo horário), dentro do
     * período. Com médico: só as que foram com ele.
     *
     * @return Builder<Schedule>
     */
    private function firstConsultationsQuery(string $entityId, Carbon $from, Carbon $until, ?string $doctorId): Builder
    {
        $attended = ScheduleSituation::Attended->value;

        return Schedule::query()
            ->with(['doctor.entityUser.user', 'covenant', 'visitType', 'patient.person'])
            ->where('schedules.entity_id', $entityId)
            ->whereNotNull('schedules.patient_id')
            ->where('schedules.situation', $attended)
            ->whereBetween('schedules.date_time', [$from, $until])
            ->when($doctorId, fn (Builder $q) => $q->where('schedules.doctor_id', $doctorId))
            ->whereNotExists(fn ($prev) => $prev->selectRaw('1')
                ->from('schedules as prev')
                ->where('prev.entity_id', $entityId)
                ->whereColumn('prev.patient_id', 'schedules.patient_id')
                ->where('prev.situation', $attended)
                ->whereNull('prev.deleted_at')
                ->where(fn ($earlier) => $earlier
                    ->whereColumn('prev.date_time', '<', 'schedules.date_time')
                    ->orWhere(fn ($tie) => $tie
                        ->whereColumn('prev.date_time', '=', 'schedules.date_time')
                        ->whereColumn('prev.id', '<', 'schedules.id'))))
            ->orderBy('schedules.date_time');
    }

    private function visitTypeLabel(Schedule $s): ?string
    {
        return $s->visitType?->name ?? $s->attendance_type?->label();
    }

    /**
     * Perfil médico (regra única de App\Support\CurrentDoctor): só os
     * agendamentos dele; sem cadastro de médico, nenhum — nunca a clínica
     * inteira. Admin/financeiro: sem recorte.
     */
    private function scopeToLoggedDoctor(Builder $query): void
    {
        if (! CurrentDoctor::isDoctorProfile()) {
            return;
        }

        $doctor = CurrentDoctor::doctor();

        $doctor
            ? $query->where('doctor_id', $doctor->id)
            : $query->whereRaw('1 = 0');
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
