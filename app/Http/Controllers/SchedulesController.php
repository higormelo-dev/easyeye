<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, EntityGate, FinancialEntryStatus, MedicalSpecialty, PatientMood, PaymentMethod, ScheduleAttendanceType, ScheduleSituation};
use App\Exceptions\Financial\{CashPeriodClosedException, DuplicateCashEntryException};
use App\Http\Requests\Financial\ScheduleCashEntryRequest;
use App\Http\Requests\ScheduleRequest;
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{Covenant, Doctor, Entity, FinancialCashEntry, FinancialCategory, IrisType, People, Procedure, Schedule, ScheduleEvent, ScheduleSituationLog, SkinType, VisitType, WaitingList};
use App\Models\DoctorWorkSchedule;
use App\Notifications\ScheduleNotification;
use App\Services\Financial\{CashFlowService, ProcedurePriceService};
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\{Cache, Gate};
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

    public function index(Request $request, ProcedurePriceService $procedurePrices): InertiaResponse
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
            // Listas fixas (não são catálogo configurável por entity como
            // covenants/visitTypes acima) — ver App\Enums\ScheduleAttendanceType
            // e App\Enums\MedicalSpecialty.
            'attendanceTypes' => ScheduleAttendanceType::options(),
            'specialties'     => MedicalSpecialty::options(),
            // Cadastro completo do paciente embutido no "+ Cadastrar" (mesmos
            // dados de Panel/Patients/Index — ver PatientsController::index()).
            'skinTypes'       => fn () => SkinType::all()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->toArray(),
            'irisTypes'       => fn () => IrisType::all()->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values()->toArray(),
            'genders'         => People::$genders,
            'maritalStatuses' => People::$maritalStatuses,
            'statesOfBrazil'  => People::$statesOfBrazil,
            'procedures'      => fn () => Procedure::where(function ($q) use ($entityId) {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })->where('active', true)->orderBy('name')->get(['id', 'name']),
            'procedurePrices'  => fn () => $procedurePrices->priceMap((string) $entityId),
            'incomeCategories' => fn () => FinancialCategory::availableForEntity($entityId)
                ->where('type', 'income')->orderBy('name')->get(['id', 'name', 'type']),
            'paymentMethods' => collect(PaymentMethod::cases())->map(fn ($m) => [
                'value'             => $m->value,
                'label'             => $m->label(),
                'uses_installments' => $m->usesInstallments(),
                'shows_credit'      => $m->showsCredit(),
                'shows_debit'       => $m->showsDebit(),
                'shows_cash'        => $m->showsCash(),
                'is_combined'       => $m->isCombined(),
                'is_courtesy'       => $m->isCourtesy(),
            ])->values(),
            'canRegisterCash' => Gate::allows(EntityGate::ViewFinancial->value, Entity::find($entityId)),
            // Lista completa e fixa — o dropdown de situação no card usa isso
            // diretamente (todas as opções sempre disponíveis, nenhuma some
            // depois de selecionada; ver ScheduleCard.vue).
            'situations' => collect(ScheduleSituation::cases())->map(fn ($s) => [
                'value'  => $s->value,
                'label'  => $s->label(),
                'badge'  => $s->badgeClass(),
                'icon'   => $s->icon(),
                'circle' => $s->circleClass(),
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

    public function show(Schedule $schedule): JsonResponse|RedirectResponse
    {
        $this->authorizeSchedule($schedule);

        // Visualização agora é exclusivamente via drawer modal na própria index.
        // Acesso direto à URL redireciona, preservando preservando o filtro de data do agendamento.
        if (! request()->wantsJson()) {
            return redirect()->route('panel.schedules.index', [
                'date' => $schedule->date_time->format('Y-m-d'),
            ]);
        }

        $schedule->load([
            'doctor.entityUser.user',
            'patient.person',
            'covenant',
            'visitType',
            'situationLogs.entityUser.user',
            'resources',
        ]);

        $patient = $schedule->patient;
        $doctor  = $schedule->doctor;

        return response()->json(['data' => [
            // ── Identificação ─────────────────────────────────────────────
            'id'            => $schedule->id,
            'code'          => $schedule->code,
            'date_time'     => $schedule->date_time->format('d/m/Y H:i'),
            'date_time_iso' => $schedule->date_time->format('Y-m-d\TH:i'),
            'date'          => $schedule->date_time->format('d/m/Y'),
            'time'          => $schedule->date_time->format('H:i'),

            // ── Paciente ──────────────────────────────────────────────────
            'patient_id'            => $schedule->patient_id,
            'patient_name'          => $patient ? $patient->person->full_name : $schedule->full_name,
            'patient_code'          => $patient?->present()->getCode(),
            'patient_is_registered' => (bool) $patient,
            'medical_records_url'   => $patient
                ? route('panel.patients.medicalrecords.index', $patient->id)
                : null,

            // ── Médico ────────────────────────────────────────────────────
            'doctor_id'     => $schedule->doctor_id,
            'doctor_name'   => $doctor?->entityUser?->user?->name,
            'doctor_code'   => $doctor?->code,
            'doctor_record' => $doctor?->record,
            'doctor_color'  => $doctor?->color,

            // ── Atendimento ───────────────────────────────────────────────
            'covenant_name'        => $schedule->covenant?->name,
            'visit_type_name'      => $schedule->visitType?->name,
            'attendance_type_name' => $schedule->attendance_type?->label(),
            'specialty_area_name'  => $schedule->specialty_area?->label(),

            // ── Situação ──────────────────────────────────────────────────
            'situation'       => $schedule->situation->value,
            'situation_label' => $schedule->situation->label(),
            'situation_badge' => $schedule->situation->badgeClass(),
            'situation_icon'  => $schedule->situation->icon(),

            // ── Contato ───────────────────────────────────────────────────
            'telephone'          => $schedule->telephone,
            'cellphone'          => $schedule->cellphone,
            'cellphone_whatsapp' => (bool) $schedule->cellphone_whatsapp,

            // ── Tempos ────────────────────────────────────────────────────
            'arrived_at'   => $schedule->arrived_at?->format('d/m/Y H:i'),
            'confirmed_at' => $schedule->confirmed_at?->format('d/m/Y H:i'),
            'created_at'   => $schedule->created_at?->format('d/m/Y H:i'),

            // ── Textos livres ─────────────────────────────────────────────
            'notes'               => $schedule->notes,
            'cancellation_reason' => $schedule->cancellation_reason,

            // ── Recursos vinculados ───────────────────────────────────────
            'resources' => $schedule->resources->map(fn ($r) => [
                'id'          => $r->id,
                'code'        => $r->code,
                'name'        => $r->name,
                'type'        => $r->type,
                'description' => $r->description,
            ])->values(),

            // ── Histórico de situações ────────────────────────────────────
            'situation_logs' => $schedule->situationLogs->map(fn ($log) => [
                'id'         => $log->id,
                'from_label' => $log->from_situation?->label(),
                'from_badge' => $log->from_situation?->badgeClass(),
                'to_label'   => $log->to_situation->label(),
                'to_badge'   => $log->to_situation->badgeClass(),
                'user_name'  => $log->entityUser?->user?->name,
                'created_at' => $log->created_at?->format('d/m/Y H:i'),
                'notes'      => $log->notes,
            ])->values(),
        ]]);
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

        // AJUSTE: situação é um campo livremente editável — inclusive pra
        // "desfazer" um Cancelado/Faltou/Atendido selecionado por engano, sem
        // precisar recriar o agendamento. Não há mais grafo de transições
        // permitidas (ScheduleSituation::allowedTransitions() foi removido) —
        // qualquer situação → qualquer situação é válido.
        //
        // Clicar na situação atual de novo é no-op: evita reset indevido de
        // arrived_at/confirmed_at (ver abaixo) e ruído no histórico.
        if ($situation === $schedule->situation) {
            return response()->json([
                'message'   => __('schedules.situation_updated'),
                'situation' => $situation->value,
                'label'     => $situation->label(),
                'badge'     => $situation->badgeClass(),
            ]);
        }

        // Concluir o atendimento NÃO depende do financeiro por padrão.
        // Apenas quando a entidade habilita `requires_cash_to_complete` é que
        // exigimos um lançamento no caixa (ou cortesia R$ 0) para marcar Atendido.
        if (
            $situation === ScheduleSituation::Attended
            && Entity::whereKey($schedule->entity_id)->value('requires_cash_to_complete')
            && ! $this->scheduleHasCashEntry($schedule)
        ) {
            return response()->json([
                'message'             => __('schedules.cash_entry_required'),
                'requires_cash_entry' => true,
            ], 422);
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

    /** Há lançamento de caixa ativo (não cancelado) para este agendamento? */
    private function scheduleHasCashEntry(Schedule $schedule): bool
    {
        return $schedule->financialEntries()
            ->where('status', '!=', FinancialEntryStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Registra um lançamento de caixa (entrada) vinculado ao agendamento,
     * disparado quando o paciente chega (situação Aguardando). Espelha o
     * fluxo "Chegou -> abre caixa" do smart_oftal.
     */
    public function storeCashEntry(ScheduleCashEntryRequest $request, Schedule $schedule, CashFlowService $cashFlowService): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        try {
            $entry = $cashFlowService->createForSchedule($schedule, $request->validated());
        } catch (DuplicateCashEntryException|CashPeriodClosedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('schedules.cash_entry_created'),
            'data'    => $entry->fresh(['category', 'covenant', 'procedure', 'doctor']),
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

        // IDs de agendamentos que já possuem lançamento de caixa ativo
        // (1 query, evita N+1 ao montar has_cash_entry por linha).
        $cashEntryScheduleIds = $schedules->isEmpty() ? [] : FinancialCashEntry::query()
            ->where('reference_type', 'schedule')
            ->whereIn('reference_id', $schedules->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->pluck('reference_id')
            ->flip()
            ->all();

        // Convênios cobráveis (faturados via guia): para esses, o caixa de
        // chegada não auto-abre nem pré-preenche o valor cheio.
        $chargingCovenantIds = app(ProcedurePriceService::class)->chargingCovenantIds($entityId);

        $scheduleRows = $schedules->map(fn ($s) => array_merge(
            ['sort_time' => $s->date_time->toISOString(), 'type' => 'schedule'],
            $this->toScheduleRow($s, $cashEntryScheduleIds, $chargingCovenantIds),
        ));

        $eventRows = $events->map(fn ($e) => array_merge(
            ['sort_time' => $e->starts_at->toISOString(), 'type' => 'event'],
            $this->toEventRow($e),
        ));

        return $scheduleRows->merge($eventRows)->sortBy('sort_time')->values()->toArray();
    }

    private function toScheduleRow(Schedule $s, array $cashEntryScheduleIds = [], array $chargingCovenantIds = []): array
    {
        $sit = $s->situation instanceof ScheduleSituation
            ? $s->situation
            : ScheduleSituation::tryFrom((int) ($s->situation ?? 0));

        $mood = $s->patient_mood instanceof PatientMood
            ? $s->patient_mood
            : ($s->patient_mood ? PatientMood::tryFrom((int) $s->patient_mood) : null);

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
            'cash_entry_url'      => route('panel.schedules.cash-entry.store', $s->id),
            'covenant_id'         => $s->covenant_id,
            'has_cash_entry'      => isset($cashEntryScheduleIds[$s->id]),
            // Convênio cobrável (faturado via guia): caixa de chegada não
            // auto-abre nem pré-preenche valor cheio — eventual lançamento é
            // tratado como co-participação.
            'bills_covenant'       => $s->covenant_id !== null && isset($chargingCovenantIds[$s->covenant_id]),
            'situation'            => $sit?->value,
            'label'                => $sit?->label() ?? '—',
            'badge'                => $sit?->badgeClass() ?? 'bg-secondary',
            'icon'                 => $sit?->icon() ?? 'fa-circle',
            'is_terminal'          => $sit?->isTerminal() ?? false,
            'notes'                => $s->notes,
            'confirmed_at'         => $s->confirmed_at?->format('d/m H:i'),
            'arrived_at'           => $s->arrived_at?->toIso8601String(),
            'covenant_name'        => $s->covenant?->name,
            'visit_name'           => $s->visitType?->name,
            'visit_procedure_id'   => $s->visitType?->procedure_id,
            'attendance_type'      => $s->attendance_type?->value,
            'attendance_type_name' => $s->attendance_type?->label(),
            'specialty_area'       => $s->specialty_area?->value,
            'specialty_area_name'  => $s->specialty_area?->label(),
            'patient_mood'         => $mood ? [
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
