<?php

namespace App\Http\Controllers;

use App\Domains\Recall\Exceptions\RecallActionException;
use App\Domains\Recall\Models\PatientRecall;
use App\Domains\Recall\Services\RecallLinkService;
use App\Domains\Recall\Support\{RecallLocks, RecallRuleInUse, RecallTaskLabel};
use App\Enums\{CashEntryReferenceType, ClientRule, DataAccessPurpose, EntityGate, FeatureKey, FinancialEntryStatus, MedicalSpecialty, PatientMood, PaymentMethod, ScheduleSituation};
use App\Exceptions\{AttendanceRequiresCashEntryException, ScheduleSlotUnavailableException};
use App\Exceptions\Financial\{CashPeriodClosedException, DuplicateCashEntryException};
use App\Http\Requests\Financial\ScheduleCashEntryRequest;
use App\Http\Requests\ScheduleRequest;
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{ClinicResource, Covenant, Doctor, Entity, FinancialCashEntry, FinancialCategory, IrisType, MedicalRecord, Patient, PatientCall, People, Procedure, Schedule, ScheduleEvent, ScheduleSituationLog, SkinType, VisitType, WaitingList};
use App\Models\DoctorWorkSchedule;
use App\Notifications\ScheduleNotification;
use App\Rules\ActiveCovenant;
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Services\{DataAccessLogService, FeatureGateService, ScheduleSeriesService, ScheduleService};
use App\Services\Financial\{CashFlowService, ProcedurePriceService};
use App\Services\Schedules\{AgendaFilters, AgendaQueryService, ScheduleAvailabilityService};
use App\Services\WhatsApp\WhatsAppService;
use App\Support\{BrazilianFormat, CurrentDoctor, PatientAccess};
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\{Cache, DB, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};
use Inertia\{Inertia, Response as InertiaResponse};
use InvalidArgumentException;

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

    public function index(Request $request, ProcedurePriceService $procedurePrices, AgendaQueryService $agenda): InertiaResponse
    {
        $entityId     = (string) session('selected_entity_id');
        $isDoctor     = CurrentDoctor::isDoctorProfile();
        $loggedDoctor = CurrentDoctor::doctor();
        // Perfil médico sem cadastro de médico: nada da clínica (antes caía na
        // visão de todos os médicos) — a tela mostra o aviso.
        $doctorMissing = $isDoctor && ! $loggedDoctor;
        // Filtros da URL (data/período, médico, turno, chip de situação,
        // especialidade, sala, busca) — escopo de médico sempre do servidor.
        $filters = AgendaFilters::fromRequest($request, $entityId, $loggedDoctor);
        // Telefone/WhatsApp do paciente só para quem tem acesso a Pacientes.
        $canSeePatients = app(PatientAccess::class)->current();

        return Inertia::render('Panel/Schedules/Index', [
            'scheduleItems' => fn () => $doctorMissing ? [] : $this->buildScheduleItems($agenda, $filters, $canSeePatients),
            // Contadores do topo (mesma conta do Dashboard) — também são os
            // chips de filtro; ignoram o próprio chip e a busca.
            'counters' => fn () => $doctorMissing
                ? [...array_fill_keys(AgendaFilters::STATUSES, 0), 'urgent_care' => null]
                : $agenda->counters($filters),
            'doctors'   => fn () => $doctorMissing ? [] : $this->buildDoctorList($entityId, $loggedDoctor),
            'covenants' => fn () => Covenant::where(function ($q) use ($entityId) {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })->where('active', true)->orderBy('name')->get(['id', 'name']),
            'visitTypes' => fn () => VisitType::where(function ($q) use ($entityId) {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })->where('active', true)->orderBy('name')->get(['id', 'name']),
            // Lista fixa (não é catálogo configurável por entity como
            // covenants/visitTypes acima) — ver App\Enums\MedicalSpecialty.
            'specialties' => MedicalSpecialty::options(),
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
            // Dropdown "Relatórios" ao lado de "Novo" (GAP fechado — antes
            // página-hub solta em /panel/reports, ver
            // App\Http\Controllers\ReportsController). Mesmo gate de
            // canRegisterCash acima (EntityGate::ViewFinancial) — quem não
            // tem acesso financeiro nem vê o botão, sem depender só do
            // middleware da rota pra esconder algo que 403aria.
            // Primeira consulta: também para o médico (com o recorte dele).
            'reportsUrls' => $this->reportsUrls($entityId, $isDoctor),
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
            'filters' => $filters->toArray(),
            // Salas/equipamentos ativos (filtro "Sala/equipamento").
            'resourceOptions' => fn () => ClinicResource::query()
                ->where('entity_id', $entityId)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (ClinicResource $r) => ['id' => $r->id, 'name' => $r->name, 'type_label' => $r->typeLabel()])
                ->values(),
            'canSeePatients' => $canSeePatients,
            // "Agendar" a partir da tela de Retornos (?recall=<id>): paciente e retorno já escolhidos no formulário.
            'recallPrefill' => fn () => $this->recallPrefill($request, $entityId, $canSeePatients),
            'urls'          => [
                'print'         => route('panel.schedules.print'),
                'availability'  => route('panel.schedules.availability'),
                'seriesPreview' => route('panel.schedules.series.preview'),
                // Contador "Pronto atendimento" leva à fila do dia.
                'urgentCare' => route('panel.urgent-care.index'),
            ],
            'isDoctor'      => $isDoctor,
            'doctorMissing' => $doctorMissing,
            'isStaff'       => in_array(session('selected_entity_user_rule'), [ClientRule::Admin->value, ClientRule::Secretary->value], true),
            // Painel de chamadas (TV da sala de espera) — opcional por clínica.
            'callPanelEnabled' => (bool) Entity::whereKey(session('selected_entity_id'))->value('call_panel_enabled'),
            't'                => trans('schedules'),
        ]);
    }

    /**
     * "Iniciar atendimento" (Agenda do médico): abre o prontuário DO
     * agendamento — o existente, se este atendimento já tem prontuário, ou o
     * create já vinculado (?schedule_id=). A transição pra "Em consulta"
     * acontece na abertura do prontuário (ScheduleFlowGuard).
     */
    public function attend(Schedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($schedule);
        abort_unless($schedule->patient_id, 404);

        $record = MedicalRecord::query()
            ->where('schedule_id', $schedule->id)
            ->whereNull('deleted_at')
            ->first();

        return $record
            ? redirect()->route('panel.patients.medicalrecords.edit', [$schedule->patient_id, $record->id])
            : redirect()->route('panel.patients.medicalrecords.create', [
                'patient'     => $schedule->patient_id,
                'schedule_id' => $schedule->id,
            ]);
    }

    /**
     * "Chamar paciente": emite chamada pro painel/TV da sala de espera.
     * Nomes são snapshot — o painel público nunca consulta o cadastro.
     */
    public function callPatient(Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        $enabled = (bool) Entity::whereKey($schedule->entity_id)->value('call_panel_enabled');
        abort_unless($enabled, 404);

        $schedule->loadMissing(['patient.person', 'doctor.entityUser.user']);

        PatientCall::create([
            'entity_id'                => $schedule->entity_id,
            'schedule_id'              => $schedule->id,
            'patient_name'             => $schedule->full_name ?: ($schedule->patient?->person?->full_name ?? '—'),
            'doctor_name'              => $schedule->doctor?->entityUser?->user?->name,
            'called_by_entity_user_id' => session('selected_entity_user_id'),
            'created_at'               => now(),
        ]);

        return response()->json(['message' => __('schedules.call_sent')]);
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

            // ── Formulário de edição (ScheduleFormModal) ──────────────────
            // Valores crus: sem eles o "Editar" abria com nome/convênio/tipo
            // vazios e o PUT apagava o convênio (inclusive o já inativado,
            // que a regra deixa manter — App\Rules\ActiveCovenant).
            'full_name'       => $schedule->full_name,
            'covenant_id'     => $schedule->covenant_id,
            'covenant_active' => $schedule->covenant ? (bool) $schedule->covenant->active : null,
            'visit_id'        => $schedule->visit_id,
            'attendance_type' => $schedule->attendance_type?->value,
            'specialty_area'  => $schedule->specialty_area?->value,

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

            // ── Encaixe, pronto atendimento e série de sessões ────────────
            'is_fit_in'          => (bool) $schedule->is_fit_in,
            'is_urgent_care'     => $schedule->isUrgentCare(),
            'series_id'          => $schedule->series_id,
            'session_number'     => $schedule->session_number,
            'session_total'      => $schedule->session_total,
            'series'             => $this->seriesRows($schedule),
            'series_cancellable' => $this->seriesCancellableCount($schedule),
            'cancel_series_url'  => $schedule->series_id ? route('panel.schedules.series.cancel-future', $schedule->id) : null,
            // Série aberta (sem total — "trate e estenda"): dá para acrescentar a próxima sessão.
            'add_session_url' => $schedule->series_id && $schedule->session_total === null ? route('panel.schedules.series.add-session', $schedule->id) : null,
            // Ficha impressa = cadastro do paciente: mesma regra de Pacientes.
            'patient_sheet_url' => $schedule->patient_id && app(PatientAccess::class)->current()
                ? route('panel.schedules.patient-sheet', $schedule->id)
                : null,
            // Alergias do paciente (Fase 2 / A1) — alerta fixo no drawer; só
            // para quem tem acesso a Pacientes.
            'clinical_safety' => $this->clinicalSafetyForDrawer($schedule),

            // ── Contato ───────────────────────────────────────────────────
            // Formatado para o drawer; o form de edição (v-mask) aceita o valor
            // formatado e o ScheduleRequest volta para só dígitos.
            'telephone'          => BrazilianFormat::phone($schedule->telephone),
            'cellphone'          => BrazilianFormat::phone($schedule->cellphone),
            'cellphone_whatsapp' => (bool) $schedule->cellphone_whatsapp,
            // Respondeu SAIR ao número que envia para a clínica: não recebe
            // confirmação/pesquisa até responder VOLTAR.
            'whatsapp_opted_out' => WhatsAppService::optedOutFor((string) $schedule->entity_id, $schedule->cellphone),
            // Conversa no WhatsApp (só com acesso a Pacientes) — mesma regra
            // da linha da agenda.
            ...(app(PatientAccess::class)->current()
                ? $this->whatsAppFields($schedule, WhatsAppService::optedOutPhones((string) $schedule->entity_id, [$this->cellphoneOf($schedule)]))
                : []),

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

    public function store(ScheduleRequest $request, ScheduleSeriesService $series): JsonResponse
    {
        $entityId    = session()->get('selected_entity_id');
        $doctorId    = (string) $request->input('doctor_id');
        $dateTime    = Carbon::parse($request->input('date_time'));
        $isFitIn     = $request->boolean('is_fit_in');
        $resourceIds = array_map('strval', (array) $request->input('resource_ids', []));

        // ── Série de sessões (datas 2..N confirmadas na prévia) ─────────────
        // Cada data passa pela regra do horário principal; qualquer conflito
        // recusa a série inteira, dizendo quais datas (nada é gravado).
        $extraDates = collect((array) $request->input('series_dates', []))
            ->map(fn ($date) => Carbon::parse($date))
            ->sort()
            ->values()
            ->all();

        if ($extraDates !== [] && ($conflicts = $series->conflicts($doctorId, $extraDates, $resourceIds, $isFitIn))) {
            return response()->json([
                'message' => trans_choice('schedules.series_has_conflicts', count($conflicts), ['count' => count($conflicts)]) . ' ' . $conflicts[0],
                'errors'  => ['series_dates' => $conflicts],
            ], 422);
        }

        // ── Recorrência antiga (semanal/mensal "até a data") ────────────────
        // Datas que o médico não atende continuam sendo puladas; sala
        // indisponível numa delas recusa o pedido inteiro, dizendo qual sala,
        // quando e por quê. As ocorrências viram uma série ("2/5").
        $recurrenceType    = $request->input('recurrence_type');  // weekly | monthly | null
        $recurrenceUntil   = $request->input('recurrence_until'); // Y-m-d | null
        $recurrenceGroupId = null;

        if ($extraDates === [] && $recurrenceType && $recurrenceUntil) {
            [$extraDates, $roomError] = $this->legacyRecurrenceDates($doctorId, $dateTime, $recurrenceType, $recurrenceUntil, $resourceIds, $isFitIn);

            if ($roomError) {
                return response()->json(['message' => $roomError, 'errors' => ['resource_ids' => [$roomError]]], 422);
            }

            $recurrenceGroupId = (string) Str::uuid();
        }

        // Nasce Agendado; a situação pedida no formulário (se houver) entra
        // pelo fluxo logo abaixo, na MESMA transação — nunca por mass
        // assignment (pulava histórico, timestamps e a trava de caixa).
        $baseData = array_merge(
            $request->scheduleAttributes(),
            [
                'entity_id'           => $entityId,
                'situation'           => ScheduleSituation::Scheduled->value,
                'is_fit_in'           => $isFitIn,
                'recurrence_group_id' => $recurrenceGroupId,
                'recurrence_type'     => $recurrenceGroupId ? $recurrenceType : null,
                'recurrence_until'    => $recurrenceGroupId ? $recurrenceUntil : null,
                'series_id'           => $extraDates !== [] ? ($recurrenceGroupId ?? (string) Str::uuid()) : null,
                'session_number'      => $extraDates !== [] ? 1 : null,
                'session_total'       => $extraDates !== [] ? count($extraDates) + 1 : null,
            ],
        );

        // Tudo ou nada: agendamento, sala, situação pedida e as demais sessões
        // da série. Encaixe confere o teto do turno sob lock antes de gravar.
        try {
            $schedule = DB::transaction(function () use ($baseData, $request, $doctorId, $dateTime, $isFitIn, $resourceIds, $extraDates, $series, $entityId): Schedule {
                if ($isFitIn) {
                    $this->service->assertFitInCapacity($doctorId, $dateTime);
                }

                // Mesma ordem de update(): cota → paciente/dia → linha. O lock
                // paciente/dia (só pra quem já nasce Atendido) sai antes do
                // INSERT, não escondido dentro de changeSituation() depois dele.
                $this->service->lockAttendanceReconciliation($request->requestedSituation(), $this->model, $baseData);

                // Agendando um retorno: a trava do paciente no recall vem ANTES do INSERT. O INSERT dispara o
                // MarksRecallDirty (linha da outbox do paciente, presa até o commit) e o vínculo (linkRecall) toma
                // depois a trava do paciente; quem confirma/edita uma condição toma a trava e só então grava a
                // outbox. Em ordem invertida as duas se esperavam (deadlock 40P01 → 500). Ordem única:
                // cota → paciente/dia → paciente (recall) → INSERT → vínculo.
                $this->lockRecallPatient($request, $baseData, $entityId);

                $schedule = $this->model->create($baseData);
                $this->applyRequestedSituation($request, $schedule);

                if ($resourceIds !== []) {
                    $schedule->resources()->sync($resourceIds);
                }

                if ($extraDates !== []) {
                    $series->createSessions($schedule, $extraDates, $resourceIds);
                }

                $this->linkRecall($request, $schedule, $entityId);

                return $schedule;
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->rethrowUnlessSlotConflict($e);
        } catch (AttendanceRequiresCashEntryException) {
            // Agendamento novo nunca tem caixa: nada foi gravado (rollback).
            return $this->cashEntryRequiredResponse();
        } catch (ScheduleSlotUnavailableException $e) {
            return $this->slotUnavailableResponse($e);
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
            'message' => $extraDates !== []
                ? __('schedules.series_created', ['count' => count($extraDates) + 1])
                : __('schedules.created'),
            'data' => $schedule,
        ], 201);
    }

    public function update(ScheduleRequest $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($schedule);

        // Tudo-ou-nada: se a situação pedida esbarrar na trava de caixa, os
        // demais campos do formulário também não são gravados (422 = nada mudou).
        $unlinkedExams = 0;

        try {
            DB::transaction(function () use ($request, $schedule, &$unlinkedExams): void {
                $attributes = $request->scheduleAttributes();

                // ORDEM ÚNICA DE LOCKS (a mesma de store()): cota do encaixe →
                // lock paciente/dia → linha do agendamento. Invertida (paciente/
                // dia antes da cota), uma criação e uma edição do mesmo
                // paciente/dia e médico/turno esperavam uma a outra (deadlock).
                //
                // Encaixe: teto do turno de destino conferido sob lock (o
                // próprio agendamento não conta).
                if (! empty($attributes['is_fit_in'])) {
                    $this->service->assertFitInCapacity((string) $attributes['doctor_id'], Carbon::parse($attributes['date_time']), (string) $schedule->id);
                }

                // Linha do agendamento e, se ele fica Atendido, locks paciente/dia da
                // reconciliação de capturas órfãs — origem E destino, em ordem
                // determinística —, ANTES do UPDATE abaixo (ver
                // ScheduleService::lockForEdit). Situação omitida = a do BANCO, lida
                // sob a trava (não a do route binding). A instância sai relida.
                $this->service->lockForEdit($schedule, $request->requestedSituation(), $attributes);

                $identityBefore = $this->service->examLinkIdentity($schedule);

                $schedule->update($attributes);
                $this->applyRequestedSituation($request, $schedule);
                $unlinkedExams = $this->service->reconcileExamLinks($schedule, $identityBefore);
                $schedule->resources()->sync($request->input('resource_ids', []));
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->rethrowUnlessSlotConflict($e);
        } catch (AttendanceRequiresCashEntryException) {
            return $this->cashEntryRequiredResponse();
        } catch (ScheduleSlotUnavailableException $e) {
            return $this->slotUnavailableResponse($e);
        }

        Cache::forget("waiting_room:{$schedule->entity_id}");

        return response()->json([
            'message' => $unlinkedExams > 0
                ? __('schedules.updated') . ' ' . trans_choice('schedules.exam_links_removed', $unlinkedExams, ['count' => $unlinkedExams])
                : __('schedules.updated'),
            'data' => $schedule->fresh(),
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

        // Transição centralizada no ScheduleService (log + timestamps + cache)
        // — mesmo caminho usado pelo prontuário (Finalizar/Dilatar/Exame).
        // Concluir o atendimento NÃO depende do financeiro por padrão; só
        // quando a entidade habilita `requires_cash_to_complete` o service
        // exige lançamento no caixa (ou cortesia R$ 0) para marcar Atendido.
        try {
            $this->service->changeSituation($schedule, $situation, session('selected_entity_user_id'), $notes);
        } catch (AttendanceRequiresCashEntryException) {
            return $this->cashEntryRequiredResponse();
        }

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
            // Achado de segurança (auditoria panel.* IDOR, rodada 2 — ID via
            // request body): Doctor não tem entity_id direto (só via
            // entity_user_id -> entity_users.entity_id), então 'uuid' sozinho
            // deixava reagendar com médico de OUTRA clínica. Mesmo closure já
            // usado em ScheduleRequest::rules() para store()/update().
            'doctor_id' => ['nullable', 'uuid', function ($attribute, $value, $fail) {
                if (! CurrentDoctor::canAccessDoctorId((string) $value)) {
                    $fail(__('schedules.doctor_only_own'));

                    return;
                }

                $exists = DB::table('doctors')
                    ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                    ->where('doctors.id', $value)
                    ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                    ->whereNull('doctors.deleted_at')
                    ->exists();

                if (! $exists) {
                    $fail(__('validation.custom.schedule.doctor_not_found'));
                }
            }],
            // Encaixe: sem o campo, a remarcação mantém o que o agendamento é.
            'is_fit_in' => ['nullable', 'boolean'],
        ]);

        $entityId = session()->get('selected_entity_id');
        $doctorId = $request->input('doctor_id') ?: $schedule->doctor_id;
        $dateTime = Carbon::parse($request->input('date_time'));
        $isFitIn  = $request->input('is_fit_in') !== null ? $request->boolean('is_fit_in') : (bool) $schedule->is_fit_in;

        // A sala/equipamento reservado acompanha a remarcação: valida escala,
        // bloqueio e conflito do recurso no novo horário (o próprio agendamento
        // antigo, que será cancelado, não conta como conflito — nem na cota de
        // encaixe do turno).
        $resourceIds = $schedule->resources()->pluck('clinic_resources.id')->map(fn ($id) => (string) $id)->all();
        $errors      = $isFitIn
            ? $this->service->validateFitIn($doctorId, $dateTime, (string) $schedule->id, $resourceIds)
            : array_merge(
                $this->service->validateSlot($doctorId, $dateTime),
                $this->service->resourceErrors($resourceIds, $dateTime, (string) $schedule->id),
            );

        if (! empty($errors)) {
            return response()->json(['message' => $errors[0], 'errors' => ['date_time' => [$errors[0]]]], 422);
        }

        // Remarcar cria um agendamento NOVO com o convênio do antigo: convênio
        // inativado no meio do caminho não passa adiante (a recepção edita o
        // agendamento e escolhe um ativo antes de remarcar).
        if ($schedule->covenant_id && ! ActiveCovenant::isActive((string) $schedule->covenant_id)) {
            $message = __('validation.custom.covenant.reschedule_inactive', [
                'name' => (string) Covenant::withoutGlobalScopes()->whereKey($schedule->covenant_id)->value('name'),
            ]);

            return response()->json(['message' => $message, 'errors' => ['covenant_id' => [$message]]], 422);
        }

        // Agendamento novo + cancelamento do antigo numa transação só (o novo
        // nunca fica sem o antigo cancelado, nem o contrário). A sessão de
        // série continua na série ("3/10"); encaixe confere o teto sob lock.
        try {
            $newSchedule = DB::transaction(function () use ($schedule, $entityId, $doctorId, $dateTime, $isFitIn, $resourceIds): Schedule {
                if ($isFitIn) {
                    $this->service->assertFitInCapacity($doctorId, $dateTime, (string) $schedule->id);
                }

                $newSchedule = $this->model->create([
                    'entity_id'          => $entityId,
                    'doctor_id'          => $doctorId,
                    'patient_id'         => $schedule->patient_id,
                    'covenant_id'        => $schedule->covenant_id,
                    'visit_id'           => $schedule->visit_id,
                    'attendance_type'    => $schedule->attendance_type,
                    'specialty_area'     => $schedule->specialty_area,
                    'full_name'          => $schedule->full_name,
                    'date_time'          => $dateTime,
                    'telephone'          => $schedule->telephone,
                    'cellphone'          => $schedule->cellphone,
                    'cellphone_whatsapp' => (bool) $schedule->cellphone_whatsapp,
                    'notes'              => $schedule->notes,
                    'situation'          => ScheduleSituation::Scheduled->value,
                    'is_fit_in'          => $isFitIn,
                    'series_id'          => $schedule->series_id,
                    'session_number'     => $schedule->session_number,
                    'session_total'      => $schedule->session_total,
                    'active'             => true,
                ]);

                if ($resourceIds !== []) {
                    $newSchedule->resources()->sync($resourceIds);
                }

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

                return $newSchedule;
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->rethrowUnlessSlotConflict($e);
        } catch (ScheduleSlotUnavailableException $e) {
            return $this->slotUnavailableResponse($e);
        }

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
            $this->doctorScopeForBulk(),
        );

        $message = __('schedules.bulk_updated', ['updated' => $result['updated']]);

        if ($result['skipped'] > 0) {
            $message .= ' ' . __('schedules.bulk_skipped_sched', ['skipped' => $result['skipped']]);
        }

        // Motivo das linhas recusadas pela validação (horário/escala do médico
        // ou sala ocupada/fora da escala/bloqueada) — as 3 primeiras na
        // mensagem, todas em `blocked`.
        if ($result['blocked'] !== []) {
            $message .= ' ' . collect($result['blocked'])->take(3)->pluck('message')->implode(' · ');
        }

        // ── Notifica cada paciente afetado pelo reagendamento ─────────────────
        foreach ($result['updated_ids'] ?? [] as $id) {
            NotifyScheduleChangeJob::dispatch($id, ScheduleNotification::EVENT_RESCHEDULED);
        }

        return response()->json([
            'message' => $message,
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'blocked' => $result['blocked'],
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
            $this->doctorScopeForBulk(),
        );

        // Contrato de bulk = sucesso parcial (200): linha barrada pela trava
        // "Atendido exige caixa" é ignorada como as demais (entra em `skipped`)
        // e sai também em `blocked`, com o motivo traduzido por linha.
        $blocked      = $result['blocked'];
        $otherSkipped = $result['skipped'] - count($blocked);

        $message = __('schedules.bulk_updated', ['updated' => $result['updated']]);

        if ($otherSkipped > 0) {
            $message .= ' ' . __('schedules.bulk_skipped_trans', ['skipped' => $otherSkipped]);
        }

        if ($blocked !== []) {
            $message .= ' ' . __('schedules.bulk_skipped_cash', ['blocked' => count($blocked)]);
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
            'blocked' => $blocked,
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

        // Perfil médico só consulta os PRÓPRIOS horários (sem cadastro: nada).
        if (! CurrentDoctor::canAccessDoctorId($doctorId)) {
            return response()->json(['has_schedule' => false, 'interval' => 15, 'slots' => []]);
        }

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
            // Encaixes usados × teto por turno no dia ("1 de 2 na manhã").
            'fit_in' => $this->service->fitInUsage($doctor, $date, $excludeId),
        ]);
    }

    /**
     * Vagas do mês para o calendário lateral (médico escolhido ou todos).
     * Perfil médico: só as dele; sem cadastro de médico, nada.
     */
    public function availability(Request $request, ScheduleAvailabilityService $availability): JsonResponse
    {
        $request->validate([
            'month'  => ['required', 'date_format:Y-m'],
            'doctor' => ['nullable', 'string', 'max:36'],
        ]);

        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());

        $entityId = (string) session('selected_entity_id');
        $doctorId = CurrentDoctor::isDoctorProfile()
            ? (string) CurrentDoctor::doctor()->id
            : (Str::isUuid((string) $request->input('doctor')) ? (string) $request->input('doctor') : null);

        if ($doctorId !== null && ! $this->doctorBelongsToEntity($doctorId, $entityId)) {
            abort(404);
        }

        return response()->json($availability->month($entityId, Carbon::createFromFormat('Y-m', $request->input('month'))->startOfMonth(), $doctorId));
    }

    /**
     * Prévia da série de sessões: as datas geradas (semanal, mensal ou dias
     * da semana; N sessões ou até a data) e o conflito de cada uma — antes de
     * salvar, para a recepção remover as que não servem.
     */
    public function seriesPreview(Request $request, ScheduleSeriesService $series): JsonResponse
    {
        $entityId  = (string) session('selected_entity_id');
        $validated = $request->validate([
            'doctor_id' => ['required', 'uuid', function ($attribute, $value, $fail) use ($entityId) {
                if (CurrentDoctor::isMissing()) {
                    $fail(CurrentDoctor::missingMessage());
                } elseif (! CurrentDoctor::canAccessDoctorId((string) $value)) {
                    $fail(__('schedules.doctor_only_own'));
                } elseif (! $this->doctorBelongsToEntity((string) $value, $entityId)) {
                    $fail(__('validation.custom.schedule.doctor_not_found'));
                }
            }],
            'date_time'      => ['required', 'date'],
            'frequency'      => ['required', Rule::in(ScheduleSeriesService::FREQUENCIES)],
            'interval_weeks' => ['nullable', 'required_if:frequency,every_weeks', 'integer', 'between:' . ScheduleSeriesService::INTERVAL_WEEKS_MIN . ',' . ScheduleSeriesService::INTERVAL_WEEKS_MAX],
            'weekdays'       => ['nullable', 'array', 'required_if:frequency,weekdays'],
            'weekdays.*'     => ['integer', 'between:0,6'],
            'end'            => ['required', Rule::in(['count', 'until'])],
            'count'          => ['nullable', 'required_if:end,count', 'integer', 'min:2', 'max:' . ScheduleSeriesService::MAX_SESSIONS],
            'until'          => ['nullable', 'required_if:end,until', 'date', 'after_or_equal:' . Carbon::parse((string) $request->input('date_time', 'now'))->toDateString()],
            'is_fit_in'      => ['nullable', 'boolean'],
            'resource_ids'   => ['nullable', 'array'],
            'resource_ids.*' => [
                'uuid',
                Rule::exists('clinic_resources', 'id')->where(fn ($q) => $q->where('entity_id', $entityId)->where('active', true)->whereNull('deleted_at')),
            ],
        ]);

        $dates = $series->generate(
            Carbon::parse($validated['date_time']),
            $validated['frequency'],
            array_map('intval', (array) ($validated['weekdays'] ?? [])),
            $validated['end'] === 'count' ? (int) $validated['count'] : null,
            $validated['end'] === 'until' ? Carbon::parse($validated['until']) : null,
            isset($validated['interval_weeks']) ? (int) $validated['interval_weeks'] : null,
        );

        $truncated = count($dates) > ScheduleSeriesService::MAX_SESSIONS;
        $dates     = array_slice($dates, 0, ScheduleSeriesService::MAX_SESSIONS);

        return response()->json([
            'sessions'  => $series->preview($validated['doctor_id'], $dates, array_map('strval', (array) ($validated['resource_ids'] ?? [])), (bool) ($validated['is_fit_in'] ?? false)),
            'truncated' => $truncated,
            'max'       => ScheduleSeriesService::MAX_SESSIONS,
        ]);
    }

    /**
     * Acrescenta UMA sessão à série ABERTA do agendamento (o intervalo se decide a cada visita): mesma regra de
     * horário do agendamento comum; horário que não cabe volta 422 com o motivo e nada é gravado. Perfil médico: só a
     * agenda dele.
     */
    public function addSeriesSession(Request $request, Schedule $schedule, ScheduleSeriesService $series): JsonResponse
    {
        $this->authorizeSchedule($schedule);
        abort_unless($schedule->series_id && $schedule->session_total === null, 404);

        if (CurrentDoctor::isDoctorProfile()) {
            abort_unless((string) $schedule->doctor_id === (string) CurrentDoctor::doctor()?->id, 403);
        }

        $dateTime = Carbon::parse($request->validate(['date_time' => ['required', 'date']])['date_time']);

        try {
            $session = DB::transaction(fn (): Schedule => $series->addSession($schedule, $dateTime));
        } catch (ScheduleSlotUnavailableException $e) {
            return $this->slotUnavailableResponse($e);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['date_time' => [$e->getMessage()]]], 422);
        }

        NotifyScheduleChangeJob::dispatch($session->id, ScheduleNotification::EVENT_CREATED);

        return response()->json(['message' => __('schedules.series_added'), 'data' => ['id' => $session->id]], 201);
    }

    /**
     * "Cancelar as futuras" da série do agendamento, com motivo: só sessões a
     * partir de agora que ainda não começaram (Agendado/Confirmado). Passadas,
     * atendidas e em andamento ficam intactas. Perfil médico: só as dele.
     */
    public function cancelSeriesFuture(Request $request, Schedule $schedule, ScheduleSeriesService $series): JsonResponse
    {
        $this->authorizeSchedule($schedule);
        abort_unless($schedule->series_id, 404);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        $cancelled = $series->cancelFuture(
            $schedule,
            trim($reason),
            session('selected_entity_user_id'),
            CurrentDoctor::isDoctorProfile() ? (string) CurrentDoctor::doctor()?->id : null,
        );

        // Mesmo aviso do cancelamento pela agenda: um por sessão cancelada.
        foreach ($cancelled as $id) {
            NotifyScheduleChangeJob::dispatch($id, ScheduleNotification::EVENT_CANCELLED, trim($reason));
        }

        return response()->json([
            'message'   => trans_choice('schedules.series_cancelled', count($cancelled), ['count' => count($cancelled)]),
            'cancelled' => count($cancelled),
        ]);
    }

    /**
     * Linhas da Agenda: agendamentos (filtrados pelo chip/busca), compromissos
     * e — no chip "Bloqueados" — os bloqueios. Período "dia" (lista e
     * calendário do dia) leva a linha completa; semana/mês, a linha compacta
     * do calendário (o payload do mês não carrega URLs/caixa de cada linha).
     */
    /**
     * Toma a trava do recall do paciente ANTES de gravar o agendamento que vai atender um retorno (`recall_id`):
     * ver o comentário em store(). Sem retorno (ou sem a feature) nada é travado — agendar comum não paga esta espera.
     *
     * @param array<string, mixed> $attributes
     */
    private function lockRecallPatient(ScheduleRequest $request, array $attributes, string $entityId): void
    {
        $recallId  = $request->input('recall_id');
        $patientId = $attributes['patient_id'] ?? null;

        if (! is_string($recallId) || $recallId === '' || ! is_string($patientId) || $patientId === ''
            || ! app(FeatureGateService::class)->can($entityId, FeatureKey::HasPatientRecall)) {
            return;
        }

        RecallLocks::patient($patientId);
    }

    /**
     * Vincula o retorno que este agendamento atende (Fase 6), dentro da transação do agendamento. Retorno recusado
     * (tratado por outra pessoa no meio do caminho, regra suspensa) NÃO derruba o agendamento: a avaliação vincula
     * o próximo agendamento aberto do paciente sozinha.
     */
    private function linkRecall(ScheduleRequest $request, Schedule $schedule, string $entityId): void
    {
        $recallId = $request->input('recall_id');

        if (! is_string($recallId) || $recallId === '' || ! app(FeatureGateService::class)->can($entityId, FeatureKey::HasPatientRecall)) {
            return;
        }

        try {
            app(RecallLinkService::class)->link($entityId, $recallId, $schedule, $request->user());
        } catch (RecallActionException) {
            // O agendamento vale; o vínculo ficou para a reavaliação.
        }
    }

    /**
     * O retorno que a tela de Retornos pediu para agendar (`?recall=<id>`): só para quem opera o recall numa clínica
     * com a feature, e só se o retorno ainda está em aberto e a regra não está suspensa. Telefone só para quem tem
     * acesso a Pacientes; o rótulo é o "o que falta" (nunca a condição clínica).
     *
     * @return array<string, mixed>|null
     */
    private function recallPrefill(Request $request, string $entityId, bool $canSeePatients): ?array
    {
        $recallId = (string) $request->query('recall', '');
        $entity   = Entity::query()->find($entityId);

        if (! Str::isUuid($recallId) || $entity === null || ! Gate::allows(EntityGate::OperateRecall->value, $entity)
            || ! app(FeatureGateService::class)->can($entityId, FeatureKey::HasPatientRecall)) {
            return null;
        }

        $recall = PatientRecall::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($recallId)->first();

        if ($recall === null || $recall->rule_suspended || ! RecallRuleInUse::for($recall) || ! $recall->lifecycle->isWorkable()) {
            return null;
        }

        $patient = Patient::query()->where('entity_id', $entityId)->with('person')->find($recall->patient_id);

        if ($patient === null) {
            return null;
        }

        return [
            'recall_id'          => (string) $recall->id,
            'recall_label'       => RecallTaskLabel::of((string) $recall->source, (string) $recall->kind, $recall->exam_key),
            'patient_id'         => (string) $patient->id,
            'full_name'          => (string) $patient->person?->full_name,
            'cellphone'          => $canSeePatients ? $patient->person?->cellphone : null,
            'telephone'          => $canSeePatients ? $patient->person?->telephone : null,
            'cellphone_whatsapp' => (bool) $patient->person?->whatsapp,
            'covenant_id'        => $patient->covenant_id,
        ];
    }

    private function buildScheduleItems(AgendaQueryService $agenda, AgendaFilters $filters, bool $canSeePatients): array
    {
        $entityId  = $filters->entityId;
        $schedules = $agenda->schedules($filters);
        $events    = $agenda->events($filters);
        $blocks    = $filters->status === 'blocked' ? $agenda->blocks($filters) : collect();

        $eventRows = $events->map(fn ($e) => array_merge(
            ['sort_time' => $e->starts_at->toISOString(), 'type' => 'event'],
            $this->toEventRow($e),
        ));

        if ($filters->range !== 'day') {
            return $schedules->map(fn (Schedule $s) => $this->toCompactRow($s))
                ->toBase()
                ->merge($eventRows->toBase())
                ->merge($blocks)
                ->sortBy('sort_time')
                ->values()
                ->toArray();
        }

        // IDs de agendamentos que já possuem lançamento de caixa ativo
        // (1 query, evita N+1 ao montar has_cash_entry por linha). Filtro
        // explícito de clínica: todos os agendamentos acima são de $entityId,
        // então lançamento de outra clínica apontando para eles (linha legada/
        // forjada) não marca a linha como paga — não depende do EntityScope.
        $cashEntryScheduleIds = $schedules->isEmpty() ? [] : FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->where('reference_type', CashEntryReferenceType::Schedule->value)
            ->whereIn('reference_id', $schedules->pluck('id'))
            ->where('status', '!=', FinancialEntryStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->pluck('reference_id')
            ->flip()
            ->all();

        // Convênios cobráveis (faturados via guia): para esses, o caixa de
        // chegada não auto-abre nem pré-preenche o valor cheio.
        $chargingCovenantIds = app(ProcedurePriceService::class)->chargingCovenantIds($entityId);

        // WhatsApp da linha: celular do agendamento (ou do cadastro) e quem
        // respondeu SAIR — uma consulta para a página inteira.
        $optedOut = $canSeePatients
            ? WhatsAppService::optedOutPhones($entityId, $schedules->map(fn (Schedule $s) => $this->cellphoneOf($s))->all())
            : [];

        // Alergias por paciente (Fase 2 / A1) — uma consulta para a página;
        // só para quem tem acesso a Pacientes.
        $allergyFlags = $canSeePatients
            ? app(PatientClinicalSafetyService::class)->compactFor($entityId, $schedules->pluck('patient_id')->filter()->all())
            : [];

        $scheduleRows = $schedules->map(fn ($s) => array_merge(
            ['sort_time' => $s->date_time->toISOString(), 'type' => 'schedule'],
            $this->toScheduleRow($s, $cashEntryScheduleIds, $chargingCovenantIds),
            $canSeePatients ? $this->whatsAppFields($s, $optedOut) : [],
            ['allergy' => $s->patient_id ? ($allergyFlags[(string) $s->patient_id] ?? null) : null],
        ));

        return $scheduleRows->toBase()
            ->merge($eventRows->toBase())
            ->merge($blocks)
            ->sortBy('sort_time')
            ->values()
            ->toArray();
    }

    /**
     * Alergias do paciente do agendamento para o drawer (Fase 2 / A1): só com
     * acesso a Pacientes e paciente cadastrado; a leitura vai para o log de
     * acesso (dado de saúde sensível).
     *
     * @return array<string, mixed>|null
     */
    private function clinicalSafetyForDrawer(Schedule $schedule): ?array
    {
        $patient = $schedule->patient;

        if ($patient === null || ! app(PatientAccess::class)->current()) {
            return null;
        }

        app(DataAccessLogService::class)->log(
            resource: $patient,
            purpose: DataAccessPurpose::PatientCare,
            justification: null,
            patientId: (string) $patient->id,
        );

        $summary = app(PatientClinicalSafetyService::class)->summary($patient, withHistory: false);

        return [
            'allergy_state' => $summary['allergy_state'],
            'denied'        => $summary['denied'],
            'allergies'     => $summary['allergies'],
        ];
    }

    /** Celular para contato: o do agendamento, senão o do cadastro do paciente. */
    private function cellphoneOf(Schedule $s): ?string
    {
        return $s->cellphone ?: $s->patient?->person?->cellphone;
    }

    /**
     * Conversa no WhatsApp pela linha da agenda (só perfis com acesso a
     * Pacientes): `ok` com o link wa.me; `opted_out` quando o celular
     * respondeu SAIR ao número da clínica; `no_number` sem celular válido.
     *
     * @param array<string, true> $optedOut celulares normalizados que saíram
     *
     * @return array{whatsapp_state: string, whatsapp_url: ?string}
     */
    private function whatsAppFields(Schedule $s, array $optedOut): array
    {
        $raw = $this->cellphoneOf($s);
        $url = WhatsAppService::chatUrl($raw);

        if ($url === null) {
            return ['whatsapp_state' => 'no_number', 'whatsapp_url' => null];
        }

        if (isset($optedOut[(string) WhatsAppService::normalizePhone($raw)])) {
            return ['whatsapp_state' => 'opted_out', 'whatsapp_url' => null];
        }

        return ['whatsapp_state' => 'ok', 'whatsapp_url' => $url];
    }

    /**
     * Linha do calendário de semana/mês: o necessário para o bloco do evento
     * (cor por situação, encaixe, sessão) e para abrir o detalhe.
     */
    private function toCompactRow(Schedule $s): array
    {
        $sit = $s->situation instanceof ScheduleSituation ? $s->situation : ScheduleSituation::tryFrom((int) $s->situation);

        return [
            'type'           => 'schedule',
            'sort_time'      => $s->date_time->toISOString(),
            'id'             => $s->id,
            'date'           => $s->date_time->format('Y-m-d'),
            'time'           => $s->date_time->format('H:i'),
            'name'           => $s->full_name ?? '—',
            'doctor_id'      => $s->doctor_id,
            'doctor_name'    => $s->doctor?->entityUser?->user?->name ?? '—',
            'doctor_color'   => $s->doctor?->color ?: '#6c757d',
            'situation'      => $sit?->value,
            'label'          => $sit?->label() ?? '—',
            'badge'          => $sit?->badgeClass() ?? 'bg-secondary',
            'is_terminal'    => $sit?->isTerminal() ?? false,
            'is_fit_in'      => (bool) $s->is_fit_in,
            'is_urgent_care' => $s->isUrgentCare(),
            'session_number' => $s->session_number,
            'session_total'  => $s->session_total,
        ];
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
            'id'           => $s->id,
            'date'         => $s->date_time->format('Y-m-d'),
            'time'         => $s->date_time->format('H:i'),
            'name'         => $s->full_name ?? '—',
            'code'         => $s->patient?->code ?? null,
            'doctor_id'    => $s->doctor_id,
            'doctor_name'  => $s->doctor?->entityUser?->user?->name ?? '—',
            'doctor_color' => $s->doctor?->color ?: '#6c757d',
            'patient_id'   => $s->patient_id,
            // aponta pro index com ?open= em vez de patients.show: show() só
            // responde JSON (usado via fetch pelo PatientDetailDrawer) e faz
            // redirect cego pro index em navegação de browser normal (<a href>),
            // perdendo qual paciente era — Index.vue lê ?open= e abre o
            // cadastro (PatientFormModal) direto no paciente certo.
            'patient_url'         => $s->patient_id ? route('panel.patients.index', ['open' => $s->patient_id]) : null,
            'medical_records_url' => $s->patient_id ? route('panel.patients.medicalrecords.index', $s->patient_id) : null,
            // "Iniciar atendimento" (Agenda do médico) — precisa de paciente
            // vinculado; abre o prontuário DESTE agendamento (attend()).
            'attend_url'     => $s->patient_id ? route('panel.schedules.attend', $s->id) : null,
            'call_url'       => route('panel.schedules.call', $s->id),
            'show_url'       => route('panel.schedules.show', $s->id),
            'situation_url'  => route('panel.schedules.situation', $s->id),
            'mood_url'       => route('panel.schedules.mood', $s->id),
            'reschedule_url' => route('panel.schedules.reschedule', $s->id),
            'cash_entry_url' => route('panel.schedules.cash-entry.store', $s->id),
            'covenant_id'    => $s->covenant_id,
            'has_cash_entry' => isset($cashEntryScheduleIds[$s->id]),
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
            // Encaixe (paciente a mais no turno), atendimento do pronto
            // atendimento (fora da grade) e sessão da série ("3/10").
            'is_fit_in'      => (bool) $s->is_fit_in,
            'is_urgent_care' => $s->isUrgentCare(),
            'series_id'      => $s->series_id,
            'session_number' => $s->session_number,
            'session_total'  => $s->session_total,
            // Sala/equipamento reservado (nome curto na linha).
            'resource_names' => $s->relationLoaded('resources') ? $s->resources->pluck('name')->values()->all() : [],
            'patient_mood'   => $mood ? [
                'value'      => $mood->value,
                'label'      => $mood->label(),
                'icon'       => $mood->icon(),
                'badge'      => $mood->badgeClass(),
                'text_class' => $mood->textClass(),
            ] : null,
        ];
    }

    /**
     * Sessões da série do agendamento (drawer): todas, na ordem — as
     * canceladas/remarcadas aparecem marcadas. Perfil médico: só as dele.
     *
     * @return list<array<string, mixed>>
     */
    private function seriesRows(Schedule $schedule): array
    {
        if (! $schedule->series_id) {
            return [];
        }

        $doctorScope = CurrentDoctor::isDoctorProfile() ? (string) CurrentDoctor::doctor()?->id : null;
        $locale      = app()->getLocale();

        return Schedule::query()
            ->where('entity_id', $schedule->entity_id)
            ->where('series_id', $schedule->series_id)
            ->when($doctorScope, fn ($q) => $q->where('doctor_id', $doctorScope))
            ->orderBy('date_time')
            ->orderBy('session_number')
            ->get()
            ->map(function (Schedule $session) use ($schedule, $locale) {
                $sit = $session->situation;

                return [
                    'id'             => $session->id,
                    'code'           => $session->code,
                    'number'         => $session->session_number,
                    'total'          => $session->session_total,
                    'date_label'     => $session->date_time->copy()->locale($locale)->isoFormat('ddd, L') . ' ' . $session->date_time->format('H:i'),
                    'date_time_iso'  => $session->date_time->format('Y-m-d\TH:i'),
                    'time'           => $session->date_time->format('H:i'),
                    'name'           => $session->full_name,
                    'doctor_id'      => $session->doctor_id,
                    'is_fit_in'      => (bool) $session->is_fit_in,
                    'label'          => $sit->label(),
                    'badge'          => $sit->badgeClass(),
                    'is_terminal'    => $sit->isTerminal(),
                    'is_past'        => $session->date_time->lt(now()),
                    'is_current'     => (string) $session->id === (string) $schedule->id,
                    'reschedule_url' => route('panel.schedules.reschedule', $session->id),
                ];
            })
            ->values()
            ->all();
    }

    /** Quantas sessões "Cancelar as futuras" alcançaria agora (0 = esconde a ação). */
    private function seriesCancellableCount(Schedule $schedule): int
    {
        if (! $schedule->series_id) {
            return 0;
        }

        $doctorScope = CurrentDoctor::isDoctorProfile() ? (string) CurrentDoctor::doctor()?->id : null;

        return Schedule::query()
            ->where('entity_id', $schedule->entity_id)
            ->where('series_id', $schedule->series_id)
            ->where('date_time', '>=', now())
            ->whereIn('situation', array_map(fn (ScheduleSituation $s) => $s->value, ScheduleSeriesService::CANCELLABLE_SITUATIONS))
            ->when($doctorScope, fn ($q) => $q->where('doctor_id', $doctorScope))
            ->count();
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

    /**
     * Relatórios do dropdown da Agenda que o perfil abre (null = nenhum, o
     * botão nem aparece): produção/absenteísmo com o Gate financeiro;
     * primeira consulta também para o médico com cadastro.
     *
     * @return array<string, string>|null
     */
    private function reportsUrls(string $entityId, bool $isDoctor): ?array
    {
        $urls = [];

        if (Gate::allows(EntityGate::ViewFinancial->value, Entity::find($entityId))) {
            $urls['production']  = route('panel.schedules.reports.production');
            $urls['absenteeism'] = route('panel.schedules.reports.absenteeism');
        }

        if ($urls !== [] || ($isDoctor && ! CurrentDoctor::isMissing())) {
            $urls['first_consultations'] = route('panel.schedules.reports.first-consultations');
        }

        return $urls === [] ? null : $urls;
    }

    private function buildDoctorList(string $entityId, ?Doctor $loggedDoctor): array
    {
        // Médico: só ele mesmo — o formulário pré-seleciona (antes a lista
        // vinha vazia e o seletor de médico do "Novo" ficava sem opções).
        $doctors = $loggedDoctor ? collect([$loggedDoctor]) : $this->doctorsByEntity($entityId);

        return $doctors->map(function ($doc) {
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
     * Recorrência antiga (semanal/mensal até a data, inclusive): datas 2..N
     * que o médico atende (as demais são puladas, como sempre). Sala/
     * equipamento indisponível numa data atendível recusa o pedido inteiro —
     * devolve a mensagem com a data, a sala e o motivo.
     *
     * @param string[] $resourceIds
     *
     * @return array{0: list<Carbon>, 1: ?string} datas válidas e o erro de sala
     */
    private function legacyRecurrenceDates(string $doctorId, Carbon $first, string $type, string $until, array $resourceIds, bool $isFitIn): array
    {
        $dates   = [];
        $current = $first->copy();
        $endDate = Carbon::parse($until)->endOfDay(); // "até o dia X" inclui o dia X

        while (count($dates) < ScheduleSeriesService::MAX_SESSIONS - 1) {
            $current = $type === 'weekly' ? $current->copy()->addWeek() : $current->copy()->addMonth();

            if ($current->gt($endDate)) {
                break;
            }

            if (! empty($this->service->validateFor($isFitIn, $doctorId, $current))) {
                continue;
            }

            if ($errors = $this->service->resourceErrors($resourceIds, $current)) {
                return [[], __('schedules.recurrence_resource_unavailable', [
                    'date'   => $current->format('d/m/Y H:i'),
                    'reason' => $errors[0],
                ])];
            }

            $dates[] = $current->copy();
        }

        return [$dates, null];
    }

    /** 422 de horário recusado dentro da transação (teto de encaixe, sessão da série). */
    private function slotUnavailableResponse(ScheduleSlotUnavailableException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors'  => [$e->field => [$e->getMessage()]],
        ], 422);
    }

    /**
     * `situation` do formulário (store/update) aplicada pelo fluxo — histórico,
     * timestamps e a trava "Atendido exige caixa" de
     * ScheduleService::changeSituation(). Mesma situação atual = no-op.
     *
     * @throws AttendanceRequiresCashEntryException
     */
    private function applyRequestedSituation(ScheduleRequest $request, Schedule $schedule): void
    {
        $situation = $request->requestedSituation();

        if ($situation === null) {
            return;
        }

        $notes = $situation === ScheduleSituation::Cancelled
            ? ($request->validated('cancellation_reason') ?: null)
            : null;

        $this->service->changeSituation($schedule, $situation, session('selected_entity_user_id'), $notes);
    }

    /**
     * 422 da trava "Atendido exige caixa" (regra em
     * ScheduleService::changeSituation). `requires_cash_entry` é o que a agenda
     * usa para abrir o lançamento e retomar; `errors.situation` atende o
     * formulário de agendamento, que lê `errors`.
     */
    private function cashEntryRequiredResponse(): JsonResponse
    {
        $message = __('schedules.cash_entry_required');

        return response()->json([
            'message'             => $message,
            'errors'              => ['situation' => [$message]],
            'requires_cash_entry' => true,
        ], 422);
    }

    /**
     * Só a violação do índice de horário do médico vira "horário ocupado"
     * (422 no campo date_time). Qualquer outra violação de unicidade (ex.:
     * código SDL, import_code) é outro problema e sobe como erro — antes era
     * mascarada como conflito de horário.
     *
     * @throws ValidationException                conflito de horário
     * @throws UniqueConstraintViolationException qualquer outra violação
     */
    private function rethrowUnlessSlotConflict(UniqueConstraintViolationException $e): never
    {
        if (! Schedule::isDoctorSlotConflict($e)) {
            throw $e;
        }

        throw ValidationException::withMessages([
            'date_time' => [__('validation.custom.schedule.doctor_datetime_unique')],
        ]);
    }

    private function authorizeSchedule(Schedule $schedule): void
    {
        abort_if(
            $schedule->entity_id !== session()->get('selected_entity_id'),
            403,
            __('schedules.unauthorized'),
        );

        // Perfil médico: só os PRÓPRIOS agendamentos (a lista já era só dele;
        // por id dava para ver/alterar o de qualquer colega). Sem cadastro de
        // médico, nenhum — com o aviso de vínculo ausente.
        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());
        abort_unless(CurrentDoctor::canAccessDoctorId($schedule->doctor_id), 403, __('schedules.unauthorized'));
    }

    /**
     * Operações em lote do perfil médico ficam restritas aos agendamentos
     * dele (os demais ids entram como ignorados); sem cadastro, 403.
     */
    private function doctorScopeForBulk(): ?string
    {
        if (! CurrentDoctor::isDoctorProfile()) {
            return null;
        }

        $doctor = CurrentDoctor::doctor();
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        return (string) $doctor->id;
    }

    private function doctorBelongsToEntity(string $doctorId, string $entityId): bool
    {
        return DB::table('doctors')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('doctors.id', $doctorId)
            ->where('entity_users.entity_id', $entityId)
            ->whereNull('doctors.deleted_at')
            ->exists();
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
