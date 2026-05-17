<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, PatientMood, ScheduleSituation};
use App\Http\Requests\ScheduleRequest;
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{Covenant, Doctor, Schedule, ScheduleEvent, ScheduleSituationLog, VisitType, WaitingList};
use App\Models\DoctorWorkSchedule;
use App\Notifications\ScheduleNotification;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};
use Inertia\{Inertia, Response as InertiaResponse};

class SchedulesController extends Controller
{
    protected Schedule $model;

    protected ScheduleService $service;

    public function __construct(Schedule $schedule, ScheduleService $scheduleService)
    {
        $this->titleController = __('actions.sidemenu.schedules');
        $this->model           = $schedule;
        $this->service         = $scheduleService;
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId     = session('selected_entity_id');
        $loggedDoctor = $this->loggedDoctor();
        $dateStr      = $request->filled('date') ? $request->string('date')->value() : now()->toDateString();
        $date         = Carbon::parse($dateStr);
        $doctor       = $loggedDoctor ? (string) $loggedDoctor->id : $request->string('doctor', 'tudo')->value();
        $bout         = $request->integer('bout', 1);
        $search       = $request->string('search')->trim()->value();

        return Inertia::render('Panel/Schedules/Index', [
            'scheduleItems' => fn () => $this->buildScheduleItems($entityId, $date, $doctor, $bout, $search, $loggedDoctor),
            'doctors'       => fn () => $this->buildDoctorList($entityId, $loggedDoctor),
            'covenants'     => fn () => Covenant::where(function ($q) use ($entityId) {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })->where('active', true)->orderBy('name')->get(['id', 'name']),
            'visitTypes' => fn () => VisitType::where(function ($q) use ($entityId) {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })->where('active', true)->orderBy('name')->get(['id', 'name']),
            'situations' => collect(ScheduleSituation::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'badge' => $s->badgeClass(),
                'icon'  => $s->icon(),
            ])->values(),
            'moods' => collect(PatientMood::cases())->map(fn ($m) => [
                'value'      => $m->value,
                'label'      => $m->label(),
                'badge'      => $m->badgeClass(),
                'icon'       => $m->icon(),
                'text_class' => $m->textClass(),
            ])->values(),
            'filters' => [
                'date'   => $date->format('Y-m-d'),
                'doctor' => $doctor,
                'bout'   => $bout,
                'search' => $search,
            ],
            'isDoctor' => ! is_null($loggedDoctor),
            'isStaff'  => in_array(session('user_rule'), [ClientRule::Admin->value, ClientRule::Secretary->value], true),
            't'        => trans('schedules'),
        ]);
    }

    /**
     * Return the rendered schedule list partial (AJAX).
     */
    public function ajaxList(Request $request)
    {
        $entityId = session()->get('selected_entity_id');
        $date     = Carbon::createFromFormat('d/m/Y', $request->string('date')->toString());
        $doctor   = $request->string('doctor')->toString();
        $bout     = $request->integer('bout');
        $search   = $request->string('search')->trim()->value();

        $query = $this->model->query()
            ->with(['doctor', 'covenant', 'patient.person', 'visitType'])
            ->leftJoin('patients', 'patients.id', '=', 'schedules.patient_id')
            ->leftJoin('people', 'people.id', '=', 'patients.person_id')
            ->where('schedules.entity_id', $entityId)
            ->whereDate('schedules.date_time', $date)
            ->select('schedules.*');

        $loggedDoctor = $this->loggedDoctor();

        if ($loggedDoctor) {
            $query->where('schedules.doctor_id', $loggedDoctor->id);
        } elseif ($doctor !== 'tudo') {
            $query->where('schedules.doctor_id', $doctor);
        }

        if ($search) {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(schedules.full_name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(people.full_name) LIKE ?', ["%{$lower}%"]);
            });
        }

        match ($bout) {
            2       => $query->whereTime('date_time', '<', '13:00:00'),
            3       => $query->whereTime('date_time', '>=', '13:00:00')->whereTime('date_time', '<', '18:00:00'),
            4       => $query->whereTime('date_time', '>=', '18:00:00'),
            default => null,
        };

        $schedules = $query->orderBy('date_time')->get();

        // ── Compromissos não-clínicos do dia ──────────────────────────────────
        $eventQuery = ScheduleEvent::with('doctor')
            ->where('entity_id', $entityId)
            ->whereDate('starts_at', $date)
            ->whereNull('deleted_at');

        if ($loggedDoctor) {
            $eventQuery->where(function ($q) use ($loggedDoctor) {
                $q->where('doctor_id', $loggedDoctor->id)->orWhereNull('doctor_id');
            });
        } elseif ($doctor !== 'tudo') {
            $eventQuery->where(function ($q) use ($doctor) {
                $q->where('doctor_id', $doctor)->orWhereNull('doctor_id');
            });
        }

        match ($bout) {
            2       => $eventQuery->whereTime('starts_at', '<', '13:00:00'),
            3       => $eventQuery->whereTime('starts_at', '>=', '13:00:00')->whereTime('starts_at', '<', '18:00:00'),
            4       => $eventQuery->whereTime('starts_at', '>=', '18:00:00'),
            default => null,
        };

        $events = $eventQuery->orderBy('starts_at')->get();

        return view('system.schedules.list', compact('schedules', 'events'));
    }

    public function show(Schedule $schedule): mixed
    {
        $this->authorizeSchedule($schedule);

        $schedule->load(['doctor.entityUser.user', 'patient.person', 'covenant', 'visitType', 'situationLogs.entityUser.user', 'resources']);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => array_merge($schedule->toArray(), [
                    'date_time' => $schedule->date_time->format('Y-m-d\TH:i'),
                ]),
            ]);
        }

        return view('system.schedules.show', compact('schedule'));
    }

    public function store(ScheduleRequest $request): JsonResponse
    {
        $entityId = session()->get('selected_entity_id');

        // ── Recorrência ──────────────────────────────────────────────────────
        $recurrenceType    = $request->input('recurrence_type');  // weekly | monthly | null
        $recurrenceUntil   = $request->input('recurrence_until'); // Y-m-d | null
        $recurrenceGroupId = ($recurrenceType && $recurrenceUntil)
            ? (string) Str::uuid()
            : null;

        $baseData = array_merge(
            $request->validated(),
            [
                'entity_id'           => $entityId,
                'recurrence_group_id' => $recurrenceGroupId,
                'recurrence_type'     => $recurrenceGroupId ? $recurrenceType : null,
                'recurrence_until'    => $recurrenceGroupId ? $recurrenceUntil : null,
            ],
        );

        try {
            $schedule = $this->model->create($baseData);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'date_time' => [__('validation.custom.schedule.doctor_datetime_unique')],
            ]);
        }

        // Sync resources (many-to-many)
        if ($resourceIds = $request->input('resource_ids')) {
            $schedule->resources()->sync($resourceIds);
        }

        // ── Gera ocorrências recorrentes ─────────────────────────────────────
        if ($recurrenceGroupId) {
            $this->createRecurringOccurrences($schedule, $recurrenceType, $recurrenceUntil, $entityId);
        }

        // Mark waiting list entry as converted if the schedule came from it
        if ($wlId = $request->input('waiting_list_id')) {
            WaitingList::where('id', $wlId)
                ->where('entity_id', $entityId)
                ->where('active', true)
                ->update([
                    'schedule_id'  => $schedule->id,
                    'scheduled_at' => now(),
                    'active'       => false,
                ]);
        }

        Cache::forget("waiting_room:{$entityId}");

        // ── Notificação de criação ────────────────────────────────────────────
        NotifyScheduleChangeJob::dispatch($schedule->id, ScheduleNotification::EVENT_CREATED);

        return response()->json([
            'message' => __('schedules.created'),
            'data'    => $schedule,
        ], 201);
    }

    public function update(ScheduleRequest $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        try {
            $schedule->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'date_time' => [__('validation.custom.schedule.doctor_datetime_unique')],
            ]);
        }

        $schedule->resources()->sync($request->input('resource_ids', []));

        Cache::forget("waiting_room:{$schedule->entity_id}");

        return response()->json([
            'message' => __('schedules.updated'),
            'data'    => $schedule->fresh(),
        ]);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        $entityId = $schedule->entity_id;
        $schedule->delete();

        Cache::forget("waiting_room:{$entityId}");

        return response()->json([
            'message' => __('schedules.deleted'),
        ]);
    }

    public function updateSituation(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        $situation = ScheduleSituation::from($request->integer('situation'));
        $notes     = $request->string('notes')->trim()->value() ?: null;

        if ($schedule->situation->isTerminal()) {
            return response()->json(['message' => __('schedules.already_finalized')], 422);
        }

        if (! in_array($situation->value, $schedule->situation->allowedTransitions(), true)) {
            return response()->json(['message' => __('schedules.invalid_transition')], 422);
        }

        $data = ['situation' => $situation->value];

        if ($situation === ScheduleSituation::Waiting) {
            $data['arrived_at'] = now();
        }

        if ($situation === ScheduleSituation::Confirmed) {
            $data['confirmed_at'] = now();
        }

        if ($situation === ScheduleSituation::Cancelled && $notes) {
            $data['cancellation_reason'] = $notes;
        }

        $fromSituation = $schedule->situation;

        $schedule->update($data);

        ScheduleSituationLog::create([
            'schedule_id'    => $schedule->id,
            'entity_user_id' => session('selected_entity_user_id'),
            'from_situation' => $fromSituation->value,
            'to_situation'   => $situation->value,
            'notes'          => $notes,
            'created_at'     => now(),
        ]);

        Cache::forget("waiting_room:{$schedule->entity_id}");

        // ── Notificações por situação ─────────────────────────────────────────
        if ($situation === ScheduleSituation::Confirmed) {
            NotifyScheduleChangeJob::dispatch($schedule->id, ScheduleNotification::EVENT_CONFIRMED);
        } elseif ($situation === ScheduleSituation::Cancelled) {
            NotifyScheduleChangeJob::dispatch($schedule->id, ScheduleNotification::EVENT_CANCELLED, $notes);
        }

        return response()->json([
            'message'   => __('schedules.situation_updated'),
            'situation' => $situation->value,
            'label'     => $situation->label(),
            'badge'     => $situation->badgeClass(),
        ]);
    }

    public function reschedule(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        $request->validate([
            'date_time' => ['required', 'date'],
            'doctor_id' => ['nullable', 'uuid'],
        ]);

        $entityId = session()->get('selected_entity_id');
        $doctorId = $request->input('doctor_id') ?: $schedule->doctor_id;
        $dateTime = Carbon::parse($request->input('date_time'));

        $errors = $this->service->validateSlot($doctorId, $dateTime);

        if (! empty($errors)) {
            return response()->json(['message' => $errors[0]], 422);
        }

        $newSchedule = $this->model->create([
            'entity_id'          => $entityId,
            'doctor_id'          => $doctorId,
            'patient_id'         => $schedule->patient_id,
            'covenant_id'        => $schedule->covenant_id,
            'visit_id'           => $schedule->visit_id,
            'full_name'          => $schedule->full_name,
            'date_time'          => $dateTime,
            'telephone'          => $schedule->telephone,
            'cellphone'          => $schedule->cellphone,
            'cellphone_whatsapp' => $schedule->cellphone_whatsapp,
            'notes'              => $schedule->notes,
            'situation'          => ScheduleSituation::Scheduled->value,
            'active'             => true,
        ]);

        $cancelNote    = __('schedules.reschedule_note', ['code' => $newSchedule->code]);
        $fromSituation = $schedule->situation;

        $schedule->update([
            'situation'           => ScheduleSituation::Cancelled->value,
            'cancellation_reason' => $cancelNote,
        ]);

        ScheduleSituationLog::create([
            'schedule_id'    => $schedule->id,
            'entity_user_id' => session('selected_entity_user_id'),
            'from_situation' => $fromSituation->value,
            'to_situation'   => ScheduleSituation::Cancelled->value,
            'notes'          => $cancelNote,
            'created_at'     => now(),
        ]);

        Cache::forget("waiting_room:{$schedule->entity_id}");

        return response()->json([
            'message'      => __('schedules.rescheduled'),
            'new_schedule' => $newSchedule,
        ], 201);
    }

    public function bulkReschedule(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['uuid'],
            'date'  => ['required', 'date'],
            'time'  => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $entityId     = session()->get('selected_entity_id');
        $entityUserId = session('selected_entity_user_id');

        $result = $this->service->bulkReschedule(
            $request->input('ids'),
            Carbon::parse($request->input('date'))->format('Y-m-d'),
            $request->input('time'),
            $entityId,
            $entityUserId,
        );

        $message = __('schedules.bulk_updated', ['updated' => $result['updated']]);

        if ($result['skipped'] > 0) {
            $message .= ' ' . __('schedules.bulk_skipped_sched', ['skipped' => $result['skipped']]);
        }

        // ── Notifica cada paciente afetado pelo reagendamento ─────────────────
        foreach ($result['updated_ids'] ?? [] as $id) {
            NotifyScheduleChangeJob::dispatch($id, ScheduleNotification::EVENT_RESCHEDULED);
        }

        return response()->json([
            'message' => $message,
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'ids'       => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'     => ['uuid'],
            'situation' => ['required', 'integer', Rule::enum(ScheduleSituation::class)],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $situation    = ScheduleSituation::from($request->integer('situation'));
        $entityId     = session()->get('selected_entity_id');
        $entityUserId = session('selected_entity_user_id');
        $notes        = $request->string('notes')->trim()->value() ?: null;

        $result = $this->service->bulkUpdateSituation(
            $request->input('ids'),
            $situation,
            $entityId,
            $entityUserId,
            $notes,
        );

        $message = __('schedules.bulk_updated', ['updated' => $result['updated']]);

        if ($result['skipped'] > 0) {
            $message .= ' ' . __('schedules.bulk_skipped_trans', ['skipped' => $result['skipped']]);
        }

        // ── Notifica cada paciente afetado pela mudança de situação ───────────
        $notifyEvent = match ($situation) {
            ScheduleSituation::Confirmed => ScheduleNotification::EVENT_CONFIRMED,
            ScheduleSituation::Cancelled => ScheduleNotification::EVENT_CANCELLED,
            default                      => null,
        };

        if ($notifyEvent) {
            foreach ($result['updated_ids'] ?? [] as $id) {
                NotifyScheduleChangeJob::dispatch($id, $notifyEvent, $notes);
            }
        }

        return response()->json([
            'message' => $message,
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function updateMood(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        $request->validate([
            'mood' => ['nullable', Rule::enum(PatientMood::class)],
        ]);

        $schedule->update(['patient_mood' => $request->input('mood')]);

        return response()->json(['message' => __('schedules.mood_updated')]);
    }

    /**
     * Return availability status for all active clinic resources at a given datetime.
     * Used by the schedule form to show which rooms/equipment are available.
     */
    public function resources(Request $request): JsonResponse
    {
        $request->validate([
            'date_time'   => ['required', 'date'],
            'schedule_id' => ['nullable', 'uuid'],
        ]);

        $entityId  = session()->get('selected_entity_id');
        $dateTime  = Carbon::parse($request->input('date_time'));
        $excludeId = $request->input('schedule_id');

        $availability = $this->service->getResourceAvailability($entityId, $dateTime, $excludeId);

        return response()->json(['data' => $availability]);
    }

    public function slots(Request $request): JsonResponse
    {
        $request->validate([
            'doctor_id'   => ['required', 'uuid'],
            'date'        => ['required', 'date_format:Y-m-d'],
            'schedule_id' => ['nullable', 'uuid'],
        ]);

        $entityId  = session()->get('selected_entity_id');
        $doctorId  = $request->input('doctor_id');
        $excludeId = $request->input('schedule_id');

        $doctor = Doctor::query()
            ->with('entityUser.entity')
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('doctors.id', $doctorId)
            ->where('entity_users.entity_id', $entityId)
            ->whereNull('doctors.deleted_at')
            ->select('doctors.*')
            ->first();

        if (! $doctor) {
            return response()->json(['has_schedule' => false, 'interval' => 15, 'slots' => []]);
        }

        $date        = Carbon::parse($request->input('date'));
        $slots       = $this->service->getAvailableSlots($doctor, $date, $excludeId);
        $hasSchedule = DoctorWorkSchedule::where('doctor_id', $doctorId)
            ->where('day_of_week', $date->dayOfWeek)
            ->exists();

        return response()->json([
            'has_schedule' => $hasSchedule,
            'interval'     => $doctor->effectiveInterval(),
            'slots'        => $slots,
        ]);
    }

    private function buildScheduleItems(string $entityId, Carbon $date, string $doctor, int $bout, string $search, ?Doctor $loggedDoctor): array
    {
        $query = Schedule::query()
            ->with(['doctor.entityUser.user', 'covenant', 'patient', 'visitType'])
            ->leftJoin('patients', 'patients.id', '=', 'schedules.patient_id')
            ->leftJoin('people', 'people.id', '=', 'patients.person_id')
            ->where('schedules.entity_id', $entityId)
            ->whereDate('schedules.date_time', $date)
            ->select('schedules.*');

        if ($loggedDoctor) {
            $query->where('schedules.doctor_id', $loggedDoctor->id);
        } elseif ($doctor !== 'tudo') {
            $query->where('schedules.doctor_id', $doctor);
        }

        if ($search) {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(schedules.full_name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(people.full_name) LIKE ?', ["%{$lower}%"]);
            });
        }

        match ($bout) {
            2       => $query->whereTime('date_time', '<', '13:00:00'),
            3       => $query->whereTime('date_time', '>=', '13:00:00')->whereTime('date_time', '<', '18:00:00'),
            4       => $query->whereTime('date_time', '>=', '18:00:00'),
            default => null,
        };

        $schedules = $query->orderBy('schedules.date_time')->get();

        $eventQuery = ScheduleEvent::with('doctor.entityUser.user')
            ->where('entity_id', $entityId)
            ->whereDate('starts_at', $date)
            ->whereNull('deleted_at');

        if ($loggedDoctor) {
            $eventQuery->where(function ($q) use ($loggedDoctor) {
                $q->where('doctor_id', $loggedDoctor->id)->orWhereNull('doctor_id');
            });
        } elseif ($doctor !== 'tudo') {
            $eventQuery->where(function ($q) use ($doctor) {
                $q->where('doctor_id', $doctor)->orWhereNull('doctor_id');
            });
        }

        match ($bout) {
            2       => $eventQuery->whereTime('starts_at', '<', '13:00:00'),
            3       => $eventQuery->whereTime('starts_at', '>=', '13:00:00')->whereTime('starts_at', '<', '18:00:00'),
            4       => $eventQuery->whereTime('starts_at', '>=', '18:00:00'),
            default => null,
        };

        $events = $eventQuery->orderBy('starts_at')->get();

        $scheduleRows = $schedules->map(fn ($s) => array_merge(
            ['sort_time' => $s->date_time->toISOString(), 'type' => 'schedule'],
            $this->toScheduleRow($s),
        ));

        $eventRows = $events->map(fn ($e) => array_merge(
            ['sort_time' => $e->starts_at->toISOString(), 'type' => 'event'],
            $this->toEventRow($e),
        ));

        return $scheduleRows->merge($eventRows)->sortBy('sort_time')->values()->toArray();
    }

    private function toScheduleRow(Schedule $s): array
    {
        $sit = $s->situation instanceof ScheduleSituation
            ? $s->situation
            : ScheduleSituation::tryFrom((int) ($s->situation ?? 0));

        $mood = $s->patient_mood instanceof PatientMood
            ? $s->patient_mood
            : ($s->patient_mood ? PatientMood::tryFrom((int) $s->patient_mood) : null);

        $allowedTransitions = collect($sit?->allowedTransitions() ?? [])->map(function ($val) {
            $to = ScheduleSituation::from($val);

            return [
                'value'     => $val,
                'label'     => $to->label(),
                'icon'      => $to->icon(),
                'is_cancel' => $to === ScheduleSituation::Cancelled,
            ];
        })->values()->toArray();

        return [
            'id'                  => $s->id,
            'time'                => $s->date_time->format('H:i'),
            'name'                => $s->full_name ?? '—',
            'code'                => $s->patient?->code ?? null,
            'doctor_id'           => $s->doctor_id,
            'doctor_name'         => $s->doctor?->entityUser?->user?->name ?? '—',
            'doctor_color'        => $s->doctor?->color ?: '#6c757d',
            'patient_id'          => $s->patient_id,
            'patient_url'         => $s->patient_id ? route('panel.patients.show', $s->patient_id) : null,
            'medical_records_url' => $s->patient_id ? route('panel.patients.medicalrecords.index', $s->patient_id) : null,
            'show_url'            => route('panel.schedules.show', $s->id),
            'situation_url'       => route('panel.schedules.situation', $s->id),
            'mood_url'            => route('panel.schedules.mood', $s->id),
            'reschedule_url'      => route('panel.schedules.reschedule', $s->id),
            'situation'           => $sit?->value,
            'label'               => $sit?->label() ?? '—',
            'badge'               => $sit?->badgeClass() ?? 'bg-secondary',
            'icon'                => $sit?->icon() ?? 'fa-circle',
            'is_terminal'         => $sit?->isTerminal() ?? false,
            'allowed_transitions' => $allowedTransitions,
            'notes'               => $s->notes,
            'confirmed_at'        => $s->confirmed_at?->format('d/m H:i'),
            'arrived_at'          => $s->arrived_at?->toIso8601String(),
            'covenant_name'       => $s->covenant?->name,
            'visit_name'          => $s->visitType?->name,
            'patient_mood'        => $mood ? [
                'value'      => $mood->value,
                'label'      => $mood->label(),
                'icon'       => $mood->icon(),
                'badge'      => $mood->badgeClass(),
                'text_class' => $mood->textClass(),
            ] : null,
        ];
    }

    private function toEventRow(ScheduleEvent $e): array
    {
        return [
            'id'            => $e->id,
            'title'         => $e->title,
            'event_type'    => $e->type,
            'color'         => $e->color ?? '#6c757d',
            'starts_at_fmt' => $e->starts_at->format('H:i'),
            'ends_at_fmt'   => $e->ends_at->format('H:i'),
            'doctor_name'   => $e->doctor?->entityUser?->user?->name ?? null,
            'notes'         => $e->notes,
        ];
    }

    private function buildDoctorList(string $entityId, ?Doctor $loggedDoctor): array
    {
        if ($loggedDoctor) {
            return [];
        }

        return $this->doctorsByEntity($entityId)->map(function ($doc) {
            $photoPath = 'system/images/users/' . $doc->user_id . '.jpg';

            return [
                'id'        => $doc->id,
                'name'      => $doc->user_name,
                'color'     => $doc->color ?: '#6c757d',
                'record'    => $doc->record,
                'photo_url' => file_exists(public_path($photoPath)) ? asset($photoPath) : null,
            ];
        })->values()->toArray();
    }

    /**
     * Generates recurring occurrences for a schedule.
     * Skips slots that fail validation (conflicts, outside work schedule, etc.).
     */
    private function createRecurringOccurrences(Schedule $base, string $type, string $until, string $entityId): void
    {
        $current = Carbon::parse($base->date_time);
        $endDate = Carbon::parse($until);

        while (true) {
            $current = $type === 'weekly'
                ? $current->copy()->addWeek()
                : $current->copy()->addMonth();

            if ($current->gt($endDate)) {
                break;
            }

            $errors = $this->service->validateSlot($base->doctor_id, $current);

            if (! empty($errors)) {
                continue;
            }

            $this->model->create([
                'entity_id'           => $entityId,
                'doctor_id'           => $base->doctor_id,
                'patient_id'          => $base->patient_id,
                'covenant_id'         => $base->covenant_id,
                'visit_id'            => $base->visit_id,
                'full_name'           => $base->full_name,
                'date_time'           => $current,
                'telephone'           => $base->telephone,
                'cellphone'           => $base->cellphone,
                'cellphone_whatsapp'  => $base->cellphone_whatsapp,
                'notes'               => $base->notes,
                'situation'           => ScheduleSituation::Scheduled->value,
                'recurrence_group_id' => $base->recurrence_group_id,
                'recurrence_type'     => $type,
                'recurrence_until'    => $until,
            ]);
        }
    }

    private function authorizeSchedule(Schedule $schedule): void
    {
        abort_if(
            $schedule->entity_id !== session()->get('selected_entity_id'),
            403,
            __('schedules.unauthorized'),
        );
    }

    private function loggedDoctor(): ?Doctor
    {
        if (session('user_rule') !== ClientRule::Doctor->value) {
            return null;
        }

        $entityUserId = session('selected_entity_user_id');

        return Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('doctors.entity_user_id', $entityUserId)
            ->select('doctors.*', 'users.name as user_name', 'users.id as user_id')
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
