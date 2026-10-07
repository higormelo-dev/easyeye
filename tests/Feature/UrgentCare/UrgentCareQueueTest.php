<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleAttendanceType, ScheduleSituation, UrgentCarePriority, UrgentCareReason, UrgentCareStatus};
use App\Models\{Covenant, Doctor, DoctorWorkSchedule, Entity, EntityUser, Patient, PatientCall, People, Schedule, ScheduleSituationLog, UrgentCareEntry, User, VisitType};
use App\Services\Schedules\ScheduleAvailabilityService;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 1 / B1 — pronto atendimento (fila de demanda espontânea do dia).
 *
 * Ordem por prioridade e chegada; escopo de médico (dele + sem médico);
 * "Atender" cria o agendamento de urgência AGORA (origem pronto atendimento,
 * fora da grade e FORA do teto de encaixes) e abre o prontuário; chamar vai
 * para o painel de chamadas; desistiu/removido com motivo; contadores da
 * Agenda = Dashboard; tenant.
 *
 * Relógio fixo: terça, 06/10/2026, 10:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true, 'call_panel_enabled' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    [$this->docUserA, $this->docEuA, $this->doctorA] = ucqDoctor($this->entity, 'DRA. ANA');
    [$this->docUserB, $this->docEuB, $this->doctorB] = ucqDoctor($this->entity, 'DR. BRUNO');
    [$this->otherDocUser, $this->otherDocEu]         = ucqDoctor($this->other, 'DR. OUTRA');

    [$this->secUser, $this->secEu] = ucqMember($this->entity, ClientRule::Secretary);
    [$this->admUser, $this->admEu] = ucqMember($this->entity, ClientRule::Admin);

    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'UNIMED', 'active' => true]);
});

function ucqDoctor(Entity $entity, string $name, bool $active = true): array
{
    $user = User::factory()->create(['name' => $name]);
    $eu   = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return [$user, $eu, Doctor::query()->create([
        'entity_user_id' => $eu->id,
        'person_id'      => People::factory()->create(['full_name' => $name])->id,
        'active'         => $active,
    ])];
}

function ucqMember(Entity $entity, ClientRule $rule): array
{
    $user = User::factory()->create();

    return [$user, createEntityUser($entity, $user, $rule->value)];
}

function ucqPatient(Entity $entity, string $name, ?string $birth = null): Patient
{
    return Patient::query()->create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => $name, 'birth_date' => $birth, 'cellphone' => '11988887777'])->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
}

function ucqEntry(object $t, Patient $patient, array $extra = []): UrgentCareEntry
{
    return UrgentCareEntry::query()->create([
        'entity_id'  => $patient->entity_id,
        'patient_id' => $patient->id,
        'reason'     => UrgentCareReason::RedEye->value,
        'priority'   => UrgentCarePriority::Normal->value,
        'status'     => UrgentCareStatus::Waiting->value,
        'arrived_at' => now(),
        ...$extra,
    ]);
}

function ucqAs(object $t, User $user, EntityUser $eu): object
{
    return $t->actingAs($user)->withSession(panelSession($eu));
}

/** Props da tela do pronto atendimento. */
function ucqPage(object $t, User $user, EntityUser $eu): array
{
    $props = null;

    ucqAs($t, $user, $eu)
        ->get(route('panel.urgent-care.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('Panel/UrgentCare/Index');
            $props = $page->toArray()['props'];
        });

    return $props;
}

function ucqAgendaCounters(object $t, User $user, EntityUser $eu, array $query = []): array
{
    $props = null;

    ucqAs($t, $user, $eu)
        ->get(route('panel.schedules.index', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props['counters'];
}

function ucqDashboardStats(object $t, User $user, EntityUser $eu): array
{
    $props = null;

    ucqAs($t, $user, $eu)
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props['stats'];
}

describe('fila: ordem, escopo e tenant', function () {
    it('ordena por prioridade (imediata, por lei, normal) e depois pela chegada', function () {
        $normalCedo  = ucqEntry($this, ucqPatient($this->entity, 'NORMAL CEDO'), ['arrived_at' => '2026-10-06 08:00:00']);
        $legal       = ucqEntry($this, ucqPatient($this->entity, 'IDOSO'), ['priority' => UrgentCarePriority::Legal->value, 'arrived_at' => '2026-10-06 09:30:00']);
        $imediataTar = ucqEntry($this, ucqPatient($this->entity, 'QUEIMADURA'), ['priority' => UrgentCarePriority::Immediate->value, 'reason' => UrgentCareReason::ChemicalBurn->value, 'arrived_at' => '2026-10-06 09:55:00']);
        $normalTarde = ucqEntry($this, ucqPatient($this->entity, 'NORMAL TARDE'), ['arrived_at' => '2026-10-06 09:00:00']);
        $imediataCed = ucqEntry($this, ucqPatient($this->entity, 'TRAUMA'), ['priority' => UrgentCarePriority::Immediate->value, 'arrived_at' => '2026-10-06 09:40:00']);

        $queue = ucqPage($this, $this->secUser, $this->secEu)['queue'];

        expect(collect($queue)->pluck('id')->all())->toBe([
            $imediataCed->id, $imediataTar->id, $legal->id, $normalCedo->id, $normalTarde->id,
        ])
            ->and(collect($queue)->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
            ->and($queue[0]['priority_key'])->toBe('immediate')
            ->and($queue[1]['reason_label'])->toBe('Queimadura química');
    });

    it('a fila é do dia: chegada de ontem e itens encerrados ficam fora', function () {
        ucqEntry($this, ucqPatient($this->entity, 'ONTEM'), ['arrived_at' => '2026-10-05 17:00:00']);
        ucqEntry($this, ucqPatient($this->entity, 'DESISTIU'), ['status' => UrgentCareStatus::Left->value, 'closed_reason' => 'foi embora', 'closed_at' => now()]);
        $hoje = ucqEntry($this, ucqPatient($this->entity, 'HOJE'));

        $props = ucqPage($this, $this->secUser, $this->secEu);

        expect(collect($props['queue'])->pluck('id')->all())->toBe([$hoje->id])
            ->and(collect($props['closed'])->pluck('patient_name')->all())->toBe(['DESISTIU']);
    });

    it('médico vê só os itens dele e os sem médico; secretária vê todos', function () {
        $dele    = ucqEntry($this, ucqPatient($this->entity, 'DA ANA'), ['preferred_doctor_id' => $this->doctorA->id]);
        $semMed  = ucqEntry($this, ucqPatient($this->entity, 'SEM MEDICO'));
        $doBruno = ucqEntry($this, ucqPatient($this->entity, 'DO BRUNO'), ['preferred_doctor_id' => $this->doctorB->id]);

        $doctorIds = collect(ucqPage($this, $this->docUserA, $this->docEuA)['queue'])->pluck('id')->sort()->values()->all();
        $staffIds  = collect(ucqPage($this, $this->secUser, $this->secEu)['queue'])->pluck('id')->sort()->values()->all();

        expect($doctorIds)->toBe(collect([$dele->id, $semMed->id])->sort()->values()->all())
            ->and($staffIds)->toBe(collect([$dele->id, $semMed->id, $doBruno->id])->sort()->values()->all());

        // E não mexe no item do colega (chamar/editar/remover).
        ucqAs($this, $this->docUserA, $this->docEuA)
            ->post(route('panel.urgent-care.call', $doBruno))
            ->assertForbidden();
        ucqAs($this, $this->docUserA, $this->docEuA)
            ->post(route('panel.urgent-care.close', $doBruno), ['status' => UrgentCareStatus::Removed->value, 'reason' => 'teste'])
            ->assertForbidden();
        expect($doBruno->fresh()->status)->toBe(UrgentCareStatus::Waiting);
    });

    it('perfil médico sem cadastro de médico não vê nada e não adiciona', function () {
        [$user, $eu] = ucqMember($this->entity, ClientRule::Doctor);
        ucqEntry($this, ucqPatient($this->entity, 'QUALQUER'));

        $props = ucqPage($this, $user, $eu);

        expect($props['queue'])->toBe([])
            ->and($props['closed'])->toBe([])
            ->and($props['doctorMissing'])->toBeTrue();

        ucqAs($this, $user, $eu)
            ->post(route('panel.urgent-care.store'), [
                'patient_id' => ucqPatient($this->entity, 'NOVO')->id,
                'reason'     => UrgentCareReason::EyePain->value,
                'priority'   => UrgentCarePriority::Normal->value,
            ])
            ->assertForbidden();
    });

    it('outra clínica nunca aparece e o item dela dá 404', function () {
        $alheio = ucqEntry($this, ucqPatient($this->other, 'DE OUTRA CLINICA'));
        $meu    = ucqEntry($this, ucqPatient($this->entity, 'DESTA CLINICA'));

        expect(collect(ucqPage($this, $this->secUser, $this->secEu)['queue'])->pluck('id')->all())->toBe([$meu->id]);

        ucqAs($this, $this->secUser, $this->secEu)->post(route('panel.urgent-care.call', $alheio))->assertNotFound();
        ucqAs($this, $this->docUserA, $this->docEuA)->post(route('panel.urgent-care.attend', $alheio))->assertNotFound();
        ucqAs($this, $this->secUser, $this->secEu)->put(route('panel.urgent-care.update', $alheio), [
            'reason' => UrgentCareReason::EyePain->value, 'priority' => UrgentCarePriority::Normal->value,
        ])->assertNotFound();
        ucqAs($this, $this->secUser, $this->secEu)->post(route('panel.urgent-care.call', 'nao-e-uuid'))->assertNotFound();
    });

    it('perfil financeiro não abre a fila (mesmo grupo da Agenda)', function () {
        [$user, $eu] = ucqMember($this->entity, ClientRule::Financial);

        ucqAs($this, $user, $eu)->get(route('panel.urgent-care.index'))->assertForbidden();
    });
});

describe('entrada na fila', function () {
    it('secretária adiciona com motivo, prioridade, médico e convênio ativos', function () {
        $patient = ucqPatient($this->entity, 'MARIA');

        ucqAs($this, $this->secUser, $this->secEu)
            ->post(route('panel.urgent-care.store'), [
                'patient_id'          => $patient->id,
                'reason'              => UrgentCareReason::ForeignBody->value,
                'reason_details'      => 'limalha de ferro no OD',
                'priority'            => UrgentCarePriority::Immediate->value,
                'preferred_doctor_id' => $this->doctorA->id,
                'covenant_id'         => $this->covenant->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $entry = UrgentCareEntry::query()->sole();

        expect($entry->entity_id)->toBe($this->entity->id)
            ->and($entry->status)->toBe(UrgentCareStatus::Waiting)
            ->and($entry->reason)->toBe(UrgentCareReason::ForeignBody)
            ->and($entry->priority)->toBe(UrgentCarePriority::Immediate)
            ->and($entry->arrived_at->toDateTimeString())->toBe('2026-10-06 10:00:00')
            ->and($entry->created_by)->toBe($this->secUser->id);
    });

    it('"Outro" exige a descrição; convênio inativo, médico e paciente de outra clínica são recusados', function () {
        $patient  = ucqPatient($this->entity, 'JOSE');
        $inactive = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => false]);

        $as = fn () => ucqAs($this, $this->secUser, $this->secEu);

        $as()->post(route('panel.urgent-care.store'), [
            'patient_id' => $patient->id, 'reason' => UrgentCareReason::Other->value, 'priority' => UrgentCarePriority::Normal->value,
        ])->assertSessionHasErrors('reason_details');

        $as()->post(route('panel.urgent-care.store'), [
            'patient_id'  => $patient->id, 'reason' => UrgentCareReason::RedEye->value, 'priority' => UrgentCarePriority::Normal->value,
            'covenant_id' => $inactive->id,
        ])->assertSessionHasErrors('covenant_id');

        $as()->post(route('panel.urgent-care.store'), [
            'patient_id'          => $patient->id, 'reason' => UrgentCareReason::RedEye->value, 'priority' => UrgentCarePriority::Normal->value,
            'preferred_doctor_id' => Doctor::query()->whereHas('entityUser', fn ($q) => $q->where('entity_id', $this->other->id))->value('id'),
        ])->assertSessionHasErrors('preferred_doctor_id');

        $as()->post(route('panel.urgent-care.store'), [
            'patient_id' => ucqPatient($this->other, 'ALHEIO')->id, 'reason' => UrgentCareReason::RedEye->value, 'priority' => UrgentCarePriority::Normal->value,
        ])->assertSessionHasErrors('patient_id');

        $as()->post(route('panel.urgent-care.store'), [
            'patient_id' => $patient->id, 'reason' => 42, 'priority' => 9,
        ])->assertSessionHasErrors(['reason', 'priority']);

        expect(UrgentCareEntry::query()->count())->toBe(0);
    });

    it('o mesmo paciente não entra duas vezes na fila de hoje', function () {
        $patient = ucqPatient($this->entity, 'DUPLO');
        ucqEntry($this, $patient);

        ucqAs($this, $this->secUser, $this->secEu)->post(route('panel.urgent-care.store'), [
            'patient_id' => $patient->id, 'reason' => UrgentCareReason::EyePain->value, 'priority' => UrgentCarePriority::Normal->value,
        ])->assertSessionHasErrors('patient_id');

        expect(UrgentCareEntry::query()->count())->toBe(1);
    });

    it('editar muda prioridade/médico mas mantém a chegada e o paciente', function () {
        $entry = ucqEntry($this, ucqPatient($this->entity, 'EDITAR'), ['arrived_at' => '2026-10-06 09:00:00']);

        ucqAs($this, $this->secUser, $this->secEu)->put(route('panel.urgent-care.update', $entry), [
            'reason'              => UrgentCareReason::SuddenVisionLoss->value,
            'priority'            => UrgentCarePriority::Immediate->value,
            'preferred_doctor_id' => $this->doctorB->id,
        ])->assertSessionHas('success');

        $entry->refresh();

        expect($entry->priority)->toBe(UrgentCarePriority::Immediate)
            ->and((string) $entry->preferred_doctor_id)->toBe($this->doctorB->id)
            ->and($entry->arrived_at->toDateTimeString())->toBe('2026-10-06 09:00:00');

        ucqAs($this, $this->secUser, $this->secEu)->put(route('panel.urgent-care.update', $entry), [
            'patient_id' => ucqPatient($this->entity, 'OUTRO')->id,
            'reason'     => UrgentCareReason::RedEye->value,
            'priority'   => UrgentCarePriority::Normal->value,
        ])->assertSessionHasErrors('patient_id');
    });
});

describe('atender', function () {
    beforeEach(function () {
        // Seg–sex 08–12, intervalo de 15 min, SEM encaixe na manhã.
        for ($d = 1; $d <= 5; $d++) {
            DoctorWorkSchedule::create(['doctor_id' => $this->doctorA->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
        }
        $this->doctorA->update(['schedule_interval' => 15, 'fit_in_limit_morning' => 0]);
    });

    it('cria o agendamento de urgência agora, já em consulta, e abre o prontuário', function () {
        $this->travelTo(Carbon::parse('2026-10-06 10:07:42'));
        VisitType::query()->withoutGlobalScopes()->firstOrCreate(['entity_id' => null, 'name' => 'URGÊNCIA'], ['active' => true]);
        $patient = ucqPatient($this->entity, 'PACIENTE URGENTE');
        $entry   = ucqEntry($this, $patient, [
            'arrived_at'  => '2026-10-06 09:40:00',
            'reason'      => UrgentCareReason::ChemicalBurn->value,
            'priority'    => UrgentCarePriority::Immediate->value,
            'covenant_id' => $this->covenant->id,
        ]);

        $response = ucqAs($this, $this->docUserA, $this->docEuA)->post(route('panel.urgent-care.attend', $entry));

        $schedule = Schedule::query()->sole();
        $response->assertRedirect(route('panel.schedules.attend', $schedule));

        expect($schedule->origin)->toBe(Schedule::ORIGIN_URGENT_CARE)
            ->and($schedule->isUrgentCare())->toBeTrue()
            ->and($schedule->is_fit_in)->toBeFalse()
            ->and($schedule->doctor_id)->toBe($this->doctorA->id)
            ->and($schedule->patient_id)->toBe($patient->id)
            ->and($schedule->covenant_id)->toBe($this->covenant->id)
            ->and($schedule->attendance_type)->toBe(ScheduleAttendanceType::Urgency)
            ->and($schedule->visitType?->name)->toBe('URGÊNCIA')
            ->and($schedule->date_time->toDateTimeString())->toBe('2026-10-06 10:07:00')
            ->and($schedule->arrived_at->toDateTimeString())->toBe('2026-10-06 09:40:00')
            ->and($schedule->situation)->toBe(ScheduleSituation::InProgress)
            ->and($schedule->full_name)->toBe('PACIENTE URGENTE')
            ->and($schedule->notes)->toContain('Queimadura química');

        $log = ScheduleSituationLog::query()->where('schedule_id', $schedule->id)->sole();
        expect($log->from_situation)->toBe(ScheduleSituation::Waiting)
            ->and($log->to_situation)->toBe(ScheduleSituation::InProgress);

        $entry->refresh();
        expect($entry->status)->toBe(UrgentCareStatus::InCare)
            ->and((string) $entry->schedule_id)->toBe((string) $schedule->id)
            ->and((string) $entry->attended_by_doctor_id)->toBe($this->doctorA->id);

        // O "Atender" do agendamento leva ao prontuário normal dele.
        ucqAs($this, $this->docUserA, $this->docEuA)
            ->get(route('panel.schedules.attend', $schedule))
            ->assertRedirect(route('panel.patients.medicalrecords.create', ['patient' => $patient->id, 'schedule_id' => $schedule->id]));
    });

    it('NÃO conta no limite de encaixe nem ocupa o horário da grade', function () {
        $this->travelTo(Carbon::parse('2026-10-06 10:30:00'));
        $this->doctorA->update(['fit_in_limit_morning' => 1]);
        $service = app(ScheduleService::class);

        // Horário 10:30 já tomado por consulta normal.
        Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'full_name' => 'AGENDADO 1030',
            'date_time' => '2026-10-06 10:30:00', 'situation' => ScheduleSituation::Confirmed->value, 'active' => true,
        ]);

        foreach (['URG UM', 'URG DOIS'] as $name) {
            ucqAs($this, $this->docUserA, $this->docEuA)
                ->post(route('panel.urgent-care.attend', ucqEntry($this, ucqPatient($this->entity, $name))))
                ->assertRedirect();
        }

        expect(Schedule::query()->where('origin', Schedule::ORIGIN_URGENT_CARE)->count())->toBe(2)
            ->and($service->fitInCount($this->doctorA->id, Carbon::parse('2026-10-06 10:30'), 'morning'))->toBe(0)
            // O encaixe da manhã continua livre (teto 1, nada usado).
            ->and($service->fitInLimitError($this->doctorA->id, Carbon::parse('2026-10-06 11:00')))->toBeNull()
            // Às 10:45 (horário da grade) a vaga normal continua livre.
            ->and($service->validateSlot($this->doctorA->id, Carbon::parse('2026-10-06 10:45')))->toBe([]);

        // Atendimento de urgência num horário da grade sem consulta normal
        // (11:30): o horário continua livre para agendar.
        Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'full_name' => 'URG 1130',
            'date_time' => '2026-10-06 11:30:00', 'situation' => ScheduleSituation::InProgress->value,
            'origin'    => Schedule::ORIGIN_URGENT_CARE, 'active' => true,
        ]);

        $slots = collect($service->getAvailableSlots($this->doctorA, Carbon::parse('2026-10-06')))->keyBy('time');
        expect($slots['10:30']['available'])->toBeFalse() // ocupado pela consulta normal
            ->and($slots['10:45']['available'])->toBeTrue()
            ->and($slots['11:30']['available'])->toBeTrue()
            ->and($service->validateSlot($this->doctorA->id, Carbon::parse('2026-10-06 11:30')))->toBe([]);

        // Vagas do mês: atendimento de urgência não consome vaga (11:00 é da
        // grade e está no futuro; só a consulta normal das 11:15 ocupa).
        Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'full_name' => 'URG FUTURA',
            'date_time' => '2026-10-06 11:00:00', 'situation' => ScheduleSituation::InProgress->value,
            'origin'    => Schedule::ORIGIN_URGENT_CARE, 'active' => true,
        ]);
        Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'full_name' => 'NORMAL 1115',
            'date_time' => '2026-10-06 11:15:00', 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        ScheduleAvailabilityService::bumpVersion($this->entity->id);
        $day = app(ScheduleAvailabilityService::class)->month($this->entity->id, Carbon::parse('2026-10-01'), $this->doctorA->id)['days']['2026-10-06'];
        expect($day['capacity'] - $day['free'])->toBe(1);
    });

    it('urgência na mesma hora de uma consulta normal não esbarra no horário único do médico', function () {
        $this->travelTo(Carbon::parse('2026-10-06 11:15:20'));
        Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'full_name' => 'NORMAL 1115',
            'date_time' => '2026-10-06 11:15:00', 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);

        ucqAs($this, $this->docUserA, $this->docEuA)
            ->post(route('panel.urgent-care.attend', ucqEntry($this, ucqPatient($this->entity, 'NA MESMA HORA'))))
            ->assertRedirect();

        expect(Schedule::query()->where('date_time', '2026-10-06 11:15:00')->count())->toBe(2);
    });

    it('fora do expediente e com bloqueio do médico também atende (é agora, com o médico presente)', function () {
        $this->travelTo(Carbon::parse('2026-10-06 12:40:00')); // almoço, fora da escala

        ucqAs($this, $this->docUserA, $this->docEuA)
            ->post(route('panel.urgent-care.attend', ucqEntry($this, ucqPatient($this->entity, 'NO ALMOCO'))))
            ->assertRedirect();

        expect(Schedule::query()->sole()->date_time->format('H:i'))->toBe('12:40');
    });

    it('só médico com cadastro ativo atende; secretária e médico inativo recebem 403', function () {
        $entry = ucqEntry($this, ucqPatient($this->entity, 'SO MEDICO'));

        ucqAs($this, $this->secUser, $this->secEu)->post(route('panel.urgent-care.attend', $entry))->assertForbidden();
        ucqAs($this, $this->admUser, $this->admEu)->post(route('panel.urgent-care.attend', $entry))->assertForbidden();

        [$inactiveUser, $inactiveEu] = ucqDoctor($this->entity, 'DR. INATIVO', active: false);
        ucqAs($this, $inactiveUser, $inactiveEu)->post(route('panel.urgent-care.attend', $entry))->assertForbidden();

        expect(Schedule::query()->count())->toBe(0)
            ->and($entry->fresh()->status)->toBe(UrgentCareStatus::Waiting);
    });

    it('item já atendido não é atendido de novo (um agendamento só)', function () {
        $entry = ucqEntry($this, ucqPatient($this->entity, 'DUAS VEZES'));

        ucqAs($this, $this->docUserA, $this->docEuA)->post(route('panel.urgent-care.attend', $entry))->assertRedirect();
        ucqAs($this, $this->docUserB, $this->docEuB)
            ->from(route('panel.urgent-care.index'))
            ->post(route('panel.urgent-care.attend', $entry))
            ->assertRedirect(route('panel.urgent-care.index'))
            ->assertSessionHas('error');

        expect(Schedule::query()->count())->toBe(1);
    });

    it('trava o item (FOR UPDATE) antes de criar o agendamento, na mesma transação', function () {
        $entry = ucqEntry($this, ucqPatient($this->entity, 'CORRIDA'));
        $log   = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
        });

        ucqAs($this, $this->docUserA, $this->docEuA)->post(route('panel.urgent-care.attend', $entry))->assertRedirect();

        $entries = collect($log);
        $lock    = $entries->search(fn ($q) => str_contains($q['sql'], 'from "urgent_care_entries"') && str_contains($q['sql'], 'for update'));
        $insert  = $entries->search(fn ($q) => str_starts_with($q['sql'], 'insert into "schedules"'));

        expect($lock)->not->toBeFalse()
            ->and($insert)->not->toBeFalse()
            ->and($lock)->toBeLessThan($insert)
            ->and($entries[$insert]['level'])->toBeGreaterThanOrEqual($entries[$lock]['level']);
    });

    it('a recepção edita o agendamento de urgência sem esbarrar na grade (horário fora do intervalo)', function () {
        $this->travelTo(Carbon::parse('2026-10-06 10:07:00'));
        ucqAs($this, $this->docUserA, $this->docEuA)
            ->post(route('panel.urgent-care.attend', ucqEntry($this, ucqPatient($this->entity, 'EDITA RECEPCAO'))));
        $schedule = Schedule::query()->sole();

        ucqAs($this, $this->secUser, $this->secEu)
            ->putJson(route('panel.schedules.update', $schedule), [
                'doctor_id'   => $this->doctorA->id,
                'full_name'   => 'EDITA RECEPCAO',
                'date_time'   => '2026-10-06 10:07',
                'covenant_id' => $this->covenant->id,
                'notes'       => 'Convênio confirmado na recepção',
                'is_fit_in'   => true,
            ])
            ->assertOk();

        $schedule->refresh();
        expect((string) $schedule->covenant_id)->toBe($this->covenant->id)
            ->and($schedule->is_fit_in)->toBeFalse() // nunca vira encaixe
            ->and($schedule->isUrgentCare())->toBeTrue();
    });
});

describe('chamar, desistiu e removido', function () {
    it('chamar manda o nome para o painel de chamadas e marca como chamado', function () {
        $entry = ucqEntry($this, ucqPatient($this->entity, 'CHAMADO'), ['preferred_doctor_id' => $this->doctorB->id]);

        ucqAs($this, $this->docUserB, $this->docEuB)->post(route('panel.urgent-care.call', $entry))->assertSessionHas('success');
        ucqAs($this, $this->secUser, $this->secEu)->post(route('panel.urgent-care.call', $entry))->assertSessionHas('success');

        $calls = PatientCall::query()->orderBy('created_at')->get();
        expect($calls)->toHaveCount(2)
            ->and($calls[0]->patient_name)->toBe('CHAMADO')
            ->and($calls[0]->doctor_name)->toBe('DR. BRUNO')
            ->and($calls[0]->entity_id)->toBe($this->entity->id)
            ->and($calls[0]->schedule_id)->toBeNull();

        $entry->refresh();
        expect($entry->status)->toBe(UrgentCareStatus::Called)
            ->and($entry->call_count)->toBe(2)
            ->and($entry->called_at)->not->toBeNull();

        // Chamado continua na fila (e nos contadores).
        expect(collect(ucqPage($this, $this->secUser, $this->secEu)['queue'])->pluck('status_key')->all())->toBe(['called']);
    });

    it('sem painel de chamadas ativo, chamar dá 404', function () {
        $this->entity->forceFill(['call_panel_enabled' => false])->save();
        $entry = ucqEntry($this, ucqPatient($this->entity, 'SEM PAINEL'));

        ucqAs($this, $this->secUser, $this->secEu)->post(route('panel.urgent-care.call', $entry))->assertNotFound();
        expect(PatientCall::query()->count())->toBe(0);
    });

    it('desistiu/removido exigem motivo, saem da fila e não voltam a mudar', function () {
        $left    = ucqEntry($this, ucqPatient($this->entity, 'DESISTIU'));
        $removed = ucqEntry($this, ucqPatient($this->entity, 'REMOVIDO'));

        ucqAs($this, $this->secUser, $this->secEu)
            ->post(route('panel.urgent-care.close', $left), ['status' => UrgentCareStatus::Left->value, 'reason' => ''])
            ->assertSessionHasErrors('reason');
        ucqAs($this, $this->secUser, $this->secEu)
            ->post(route('panel.urgent-care.close', $left), ['status' => UrgentCareStatus::InCare->value, 'reason' => 'x'])
            ->assertSessionHasErrors('status');

        ucqAs($this, $this->secUser, $this->secEu)
            ->post(route('panel.urgent-care.close', $left), ['status' => UrgentCareStatus::Left->value, 'reason' => 'Foi embora sem avisar'])
            ->assertSessionHas('success');
        ucqAs($this, $this->docUserA, $this->docEuA)
            ->post(route('panel.urgent-care.close', $removed), ['status' => UrgentCareStatus::Removed->value, 'reason' => 'Cadastrado por engano'])
            ->assertSessionHas('success');

        expect($left->fresh()->status)->toBe(UrgentCareStatus::Left)
            ->and($left->fresh()->closed_reason)->toBe('Foi embora sem avisar')
            ->and($removed->fresh()->status)->toBe(UrgentCareStatus::Removed);

        $props = ucqPage($this, $this->secUser, $this->secEu);
        expect($props['queue'])->toBe([])
            ->and(collect($props['closed'])->pluck('status_key')->sort()->values()->all())->toBe(['left', 'removed']);

        // Já saiu da fila: atender/chamar/remover de novo não muda nada.
        ucqAs($this, $this->docUserA, $this->docEuA)->post(route('panel.urgent-care.attend', $left))->assertSessionHas('error');
        ucqAs($this, $this->secUser, $this->secEu)->post(route('panel.urgent-care.call', $left))->assertSessionHas('error');
        expect(Schedule::query()->count())->toBe(0)
            ->and(PatientCall::query()->count())->toBe(0);
    });
});

describe('contadores da Agenda = Dashboard', function () {
    beforeEach(function () {
        ucqEntry($this, ucqPatient($this->entity, 'DA ANA'), ['preferred_doctor_id' => $this->doctorA->id]);
        ucqEntry($this, ucqPatient($this->entity, 'SEM MEDICO'), ['status' => UrgentCareStatus::Called->value, 'called_at' => now()]);
        ucqEntry($this, ucqPatient($this->entity, 'DO BRUNO'), ['preferred_doctor_id' => $this->doctorB->id]);
        // Fora da conta: atendido, desistiu, ontem e outra clínica.
        ucqEntry($this, ucqPatient($this->entity, 'ATENDIDO'), ['status' => UrgentCareStatus::InCare->value]);
        ucqEntry($this, ucqPatient($this->entity, 'DESISTIU'), ['status' => UrgentCareStatus::Left->value]);
        ucqEntry($this, ucqPatient($this->entity, 'ONTEM'), ['arrived_at' => '2026-10-05 10:00:00']);
        ucqEntry($this, ucqPatient($this->other, 'OUTRA'));
    });

    it('secretária: fila da clínica inteira, igual nos dois', function () {
        expect(ucqAgendaCounters($this, $this->secUser, $this->secEu)['urgent_care'])->toBe(3)
            ->and(ucqDashboardStats($this, $this->secUser, $this->secEu)['urgent_queue'])->toBe(3);
    });

    it('médico: os dele + os sem médico, igual nos dois (e igual à tela da fila)', function () {
        expect(ucqAgendaCounters($this, $this->docUserA, $this->docEuA)['urgent_care'])->toBe(2)
            ->and(ucqDashboardStats($this, $this->docUserA, $this->docEuA)['urgent_queue'])->toBe(2)
            ->and(ucqPage($this, $this->docUserA, $this->docEuA)['queue'])->toHaveCount(2);
    });

    it('Agenda filtrada por médico conta os dele + os sem médico; outro dia não mostra a fila', function () {
        expect(ucqAgendaCounters($this, $this->secUser, $this->secEu, ['doctor' => $this->doctorB->id])['urgent_care'])->toBe(2)
            ->and(ucqAgendaCounters($this, $this->secUser, $this->secEu, ['date' => '2026-10-07'])['urgent_care'])->toBeNull()
            // Semana que contém hoje: mostra.
            ->and(ucqAgendaCounters($this, $this->secUser, $this->secEu, ['range' => 'week'])['urgent_care'])->toBe(3);
    });

    it('financeiro não recebe a fila no Dashboard', function () {
        [$user, $eu] = ucqMember($this->entity, ClientRule::Financial);

        expect(ucqDashboardStats($this, $user, $eu))->not->toHaveKey('urgent_queue');
    });
});

describe('cadastro rápido com nascimento (duplicado × homônimo)', function () {
    it('nome + nascimento iguais bloqueiam; outro nascimento pede confirmação; o cadastro grava o nascimento', function () {
        ucqPatient($this->entity, 'Ana Lima', '1950-03-02');

        $as = fn () => ucqAs($this, $this->secUser, $this->secEu);

        $as()->postJson(route('panel.patients.quick'), ['name' => 'ANA LIMA', 'cellphone' => '11999990000', 'birth_date' => '1950-03-02'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $as()->postJson(route('panel.patients.quick'), ['name' => 'Ana Lima', 'cellphone' => '11999990000', 'birth_date' => '1990-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_homonym');

        $created = $as()->postJson(route('panel.patients.quick'), [
            'name' => 'Ana Lima', 'cellphone' => '11999990000', 'birth_date' => '1990-01-01', 'confirm_homonym' => true,
        ])->assertCreated()->json('patient');

        expect($created['age'])->toBe(36)
            ->and(Patient::query()->find($created['id'])->person->birth_date->toDateString())->toBe('1990-01-01');

        $as()->postJson(route('panel.patients.quick'), ['name' => 'Futuro', 'cellphone' => '11999990000', 'birth_date' => '2030-01-01'])
            ->assertJsonValidationErrors('birth_date');
    });

    it('a busca de paciente traz idade e convênio (prioridade por lei sugerida na tela)', function () {
        $p = ucqPatient($this->entity, 'Idoso Busca', '1950-10-07');

        $rows = ucqAs($this, $this->secUser, $this->secEu)->getJson(route('panel.patients.search', ['q' => 'Idoso Busca']))->assertOk()->json();

        expect($rows[0]['age'])->toBe(75)
            ->and($rows[0]['covenant_id'])->toBe($p->covenant_id)
            ->and($rows[0])->not->toHaveKey('birth_date');
    });
});

describe('menu e idiomas', function () {
    it('o item "Pronto atendimento" aparece para admin, médico e secretária, não para o financeiro', function () {
        $navOf = function (User $user, EntityUser $eu): array {
            $props = null;
            ucqAs($this, $user, $eu)->get(route('panel.dashboard'))->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

            return collect($props['nav'] ?? [])->pluck('key')->filter()->all();
        };

        [$finUser, $finEu] = ucqMember($this->entity, ClientRule::Financial);

        expect($navOf($this->secUser, $this->secEu))->toContain('urgent-care')
            ->and($navOf($this->docUserA, $this->docEuA))->toContain('urgent-care')
            ->and($navOf($this->admUser, $this->admEu))->toContain('urgent-care')
            ->and($navOf($finUser, $finEu))->not->toContain('urgent-care');
    });

    it('urgent_care.php tem as mesmas chaves em pt_BR e en', function () {
        $pt = require lang_path('pt_BR/urgent_care.php');
        $en = require lang_path('en/urgent_care.php');

        expect(array_keys($en))->toEqualCanonicalizing(array_keys($pt));

        foreach (UrgentCareReason::cases() as $reason) {
            expect($pt)->toHaveKey('reason_' . $reason->key());
        }

        foreach (UrgentCarePriority::cases() as $priority) {
            expect($pt)->toHaveKey('priority_' . $priority->key())->toHaveKey('priority_' . $priority->key() . '_hint');
        }

        foreach (UrgentCareStatus::cases() as $status) {
            expect($pt)->toHaveKey('status_' . $status->key());
        }
    });
});
