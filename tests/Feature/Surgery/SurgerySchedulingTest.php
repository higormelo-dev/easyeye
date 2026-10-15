<?php

declare(strict_types=1);

use App\Enums\ScheduleSituation;
use App\Enums\Surgery\{AuthorizationStatus, SurgicalCaseStatus};
use App\Exceptions\ScheduleSlotUnavailableException;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{ClinicResource, Doctor, DoctorWorkSchedule, Patient, ResourceBlock, ResourceWorkSchedule, Schedule, ScheduleSituationLog};
use App\Models\Surgery\{SurgeryFacility, SurgeryRoom, SurgicalCase};
use App\Models\Surgery\SurgicalTeamMember;
use App\Services\{ExternalExamImportService, ScheduleService};
use App\Services\Schedules\ScheduleAvailabilityService;
use App\Services\Surgery\{SurgeryAuthorizationService, SurgeryScheduleService, SurgicalCaseService};
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

/*
 * Módulo cirúrgico (Fase 4) / M2 — agenda cirúrgica: janela por INTERVALO,
 * conflito de sala, cirurgião (e equipe), paciente, escala/bloqueio da sala,
 * consultas do cirurgião e a Agenda de consultas respeitando a cirurgia.
 *
 * Relógio fixo: quinta-feira, 15/10/2026, 10:00. Cirurgias na segunda 19/10.
 */

const SSC_DAY = '2026-10-19';

function sscFacility(object $w, string $name = 'Hospital A', string $type = 'hospital'): SurgeryFacility
{
    return SurgeryFacility::query()->create(['entity_id' => $w->entity->id, 'name' => $name, 'type' => $type, 'active' => true]);
}

function sscRoom(SurgeryFacility $facility, string $name = 'Sala 1', ?string $resourceId = null): SurgeryRoom
{
    return SurgeryRoom::query()->create([
        'entity_id'          => $facility->entity_id, 'surgery_facility_id' => $facility->id, 'name' => $name,
        'clinic_resource_id' => $resourceId, 'active' => true,
    ]);
}

/**
 * Caso AUTORIZADO (dispensa = sem validade) pronto para agendar. `surgeon` e
 * `patient` próprios por padrão, para os casos não conflitarem sem querer.
 *
 * @param array<string, mixed> $over
 */
function sscCase(object $w, array $over = [], ?array $auth = null): SurgicalCase
{
    $surgeon = $over['surgeon'] ?? createDoctorForEntity($w->entity);
    $patient = $over['patient'] ?? Patient::factory()->create(['entity_id' => $w->entity->id, 'covenant_id' => $w->particular->id, 'card_number' => null, 'active' => true]);
    unset($over['surgeon'], $over['patient']);

    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id'        => (string) $patient->id,
        'surgeon_doctor_id' => (string) $surgeon->id,
        'title'             => 'Facoemulsificação',
        'eye'               => 'od',
        'procedures'        => [['description' => 'Facoemulsificação com LIO', 'quantity' => 1]],
    ], $over));

    $authorization = app(SurgeryAuthorizationService::class);
    $authorization->request($case);
    $authorization->record($case, $auth ?? ['type' => 'waived', 'note' => 'Cirurgia particular']);

    return $case->refresh();
}

/** @return array<string, mixed> */
function sscWindow(SurgeryFacility $facility, ?SurgeryRoom $room, string $time = '09:00', int $minutes = 60, string $day = SSC_DAY): array
{
    return [
        'starts_at'           => "{$day}T{$time}",
        'duration_minutes'    => $minutes,
        'surgery_facility_id' => (string) $facility->id,
        'surgery_room_id'     => $room?->id,
    ];
}

function sscSchedule(object $w, SurgicalCase $case, SurgeryFacility $facility, ?SurgeryRoom $room, string $time = '09:00', int $minutes = 60): SurgicalCase
{
    return app(SurgeryScheduleService::class)->schedule($case, sscWindow($facility, $room, $time, $minutes));
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->w     = surgeryWorld();
    $this->hosp  = sscFacility($this->w);
    $this->room1 = sscRoom($this->hosp, 'Sala 1');
    $this->room2 = sscRoom($this->hosp, 'Sala 2');
});

// ── Agendar ─────────────────────────────────────────────────────────────────

it('agenda o caso autorizado: janela, local, sala e histórico', function () {
    $case = sscCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1) + ['expected_revision' => $case->revision])
        ->assertOk();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Scheduled)
        ->and($case->scheduled_start->format('Y-m-d H:i'))->toBe('2026-10-19 09:00')
        ->and($case->scheduled_end->format('Y-m-d H:i'))->toBe('2026-10-19 10:00')
        ->and((string) $case->surgery_room_id)->toBe((string) $this->room1->id)
        ->and((string) $case->surgery_facility_id)->toBe((string) $this->hosp->id)
        ->and($case->estimated_duration_minutes)->toBe(60)
        ->and(last($case->status_history)['to'])->toBe('scheduled');
});

it('usa a duração estimada do caso quando a janela não traz duração', function () {
    $case = sscCase($this->w, ['estimated_duration_minutes' => 45]);

    $data = sscWindow($this->hosp, $this->room1);
    unset($data['duration_minutes']);

    $scheduled = app(SurgeryScheduleService::class)->schedule($case, $data);

    expect($scheduled->scheduled_end->format('H:i'))->toBe('09:45');
});

it('recusa o que não está autorizado (rascunho e aguardando)', function () {
    $draft = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Rascunho', 'eye' => 'oe', 'procedures' => [['description' => 'Algo']],
    ]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $draft), sscWindow($this->hosp, $this->room1))
        ->assertStatus(409);

    app(SurgeryAuthorizationService::class)->request($draft);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $draft), sscWindow($this->hosp, $this->room1))
        ->assertStatus(409);

    expect($draft->refresh()->scheduled_start)->toBeNull();
});

it('não agenda para depois do fim da validade da autorização; no último dia vale', function () {
    $case = sscCase($this->w, [], ['type' => 'authorized', 'authorization_number' => 'A-1', 'valid_until' => '2026-10-17']);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1))
        ->assertStatus(409)
        ->assertJsonPath('message', __('surgery_schedule.errors.authorization_expires_before', ['valid_until' => '17/10/2026', 'date' => '19/10/2026']));

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1, day: '2026-10-17'))
        ->assertOk();
});

it('autorização vencida (job) não deixa agendar', function () {
    $case = sscCase($this->w, [], ['type' => 'authorized', 'authorization_number' => 'A-2', 'valid_until' => '2026-10-16']);

    $this->travelTo(Carbon::parse('2026-10-17 06:00:00'));
    app(SurgeryAuthorizationService::class)->expireDue();

    expect($case->refresh()->authorization_status)->toBe(AuthorizationStatus::Expired);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1, day: '2026-10-19'))
        ->assertStatus(409);
});

it('recusa horário no passado e duração fora de 5 min a 12 h', function () {
    $case = sscCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1, day: '2026-10-14'))
        ->assertStatus(422);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1, minutes: 721))
        ->assertStatus(422)
        ->assertJsonValidationErrors('duration_minutes');
});

it('revisão velha é 409 e não grava', function () {
    $case = sscCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1) + ['expected_revision' => $case->revision - 1])
        ->assertStatus(409);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized);
});

// ── Conflito por intervalo ──────────────────────────────────────────────────

it('sala ocupada: sobreposição parcial conflita, adjacência (fim = início) não', function () {
    sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1, '09:00', 60);

    $partial = sscCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $partial), sscWindow($this->hosp, $this->room1, '09:30'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'room');

    // Contido na janela, e pegando só o começo: também conflitam.
    foreach (['09:15' => 15, '08:30' => 45] as $time => $minutes) {
        surgeryAs($this->w, 'secretary')
            ->postJson(route('panel.surgery.schedule.store', $partial), sscWindow($this->hosp, $this->room1, $time, $minutes))
            ->assertStatus(409);
    }

    // Antes (termina às 09:00) e depois (começa às 10:00): livres.
    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $partial), sscWindow($this->hosp, $this->room1, '08:00'))
        ->assertOk();

    $after = sscCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $after), sscWindow($this->hosp, $this->room1, '10:00'))
        ->assertOk();
});

it('outra sala no mesmo horário não conflita', function () {
    sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($this->hosp, $this->room2))
        ->assertOk();
});

it('cirurgião ocupado em outra sala conflita (kind surgeon)', function () {
    $surgeon = createDoctorForEntity($this->w->entity);
    sscSchedule($this->w, sscCase($this->w, ['surgeon' => $surgeon]), $this->hosp, $this->room1);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w, ['surgeon' => $surgeon])), sscWindow($this->hosp, $this->room2, '09:30'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'surgeon');
});

it('médico da equipe de outro caso conflita (kind team)', function () {
    $assistant = createDoctorForEntity($this->w->entity);
    $busy      = sscCase($this->w, ['team' => [['role' => 'assistant', 'doctor_id' => (string) $assistant->id]]]);
    sscSchedule($this->w, $busy, $this->hosp, $this->room1);

    // O mesmo médico agora é o CIRURGIÃO de outro caso, noutra sala.
    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w, ['surgeon' => $assistant])), sscWindow($this->hosp, $this->room2, '09:30'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'team');

    // E também quando ele entra na equipe de um segundo caso.
    $second = sscCase($this->w, ['team' => [['role' => 'assistant', 'doctor_id' => (string) $assistant->id]]]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $second), sscWindow($this->hosp, $this->room2, '09:00'))
        ->assertStatus(409);
});

it('o mesmo paciente não opera em duas salas ao mesmo tempo (kind patient)', function () {
    $patient = Patient::factory()->create(['entity_id' => $this->w->entity->id, 'covenant_id' => $this->w->particular->id, 'active' => true]);
    sscSchedule($this->w, sscCase($this->w, ['patient' => $patient]), $this->hosp, $this->room1);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w, ['patient' => $patient, 'eye' => 'oe'])), sscWindow($this->hosp, $this->room2, '09:30'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'patient');
});

it('caso realizado ainda ocupa a janela; cancelado, suspenso e só autorizado não', function () {
    $performed = sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1);
    $performed->forceFill(['status' => SurgicalCaseStatus::Performed])->save();

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($this->hosp, $this->room1))
        ->assertStatus(409);

    foreach ([SurgicalCaseStatus::Cancelled, SurgicalCaseStatus::Suspended] as $status) {
        $performed->forceFill(['status' => $status])->save();

        surgeryAs($this->w, 'secretary')
            ->postJson(route('panel.surgery.schedule.store', $other = sscCase($this->w)), sscWindow($this->hosp, $this->room1))
            ->assertOk();

        $other->forceFill(['status' => SurgicalCaseStatus::Cancelled])->save();
    }
});

it('a conferência antecipada lista os conflitos sem gravar nada', function () {
    sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1);
    $case = sscCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.check', $case), sscWindow($this->hosp, $this->room1, '09:30'))
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('conflicts.0.kind', 'room')
        ->assertJsonPath('conflicts.0.text', __('surgery_schedule.conflict.room', ['label' => 'Sala 1', 'case' => SurgicalCase::query()->where('scheduled_start', '!=', null)->first()->code, 'start' => '19/10/2026 09:00', 'end' => '10:00']));

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.check', $case), sscWindow($this->hosp, $this->room2, '09:30'))
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized)->and($case->scheduled_start)->toBeNull();
});

// ── Escala e bloqueios da sala própria ──────────────────────────────────────

function sscOwnRoom(object $test, array $schedule = [['08:00', '12:00']], int $dayOfWeek = 1): SurgeryRoom
{
    $resource = ClinicResource::create(['entity_id' => $test->w->entity->id, 'name' => 'CENTRO CIRURGICO', 'type' => 'room', 'active' => true]);

    foreach ($schedule as [$from, $to]) {
        ResourceWorkSchedule::create(['resource_id' => $resource->id, 'day_of_week' => $dayOfWeek, 'starts_at' => "{$from}:00", 'ends_at' => "{$to}:00"]);
    }

    return sscRoom(sscFacility($test->w, 'Clínica', 'own_clinic'), 'Sala própria', (string) $resource->id);
}

it('escala da sala é conferida pelo intervalo inteiro, não só pelo início', function () {
    $room = sscOwnRoom($this);
    $own  = $room->facility;

    // 11:30–12:30 começa dentro da escala (08–12) mas termina fora.
    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($own, $room, '11:30'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'resource_schedule');

    // Termina exatamente às 12:00: cabe.
    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($own, $room, '11:00'))
        ->assertOk();

    // Dia sem escala (terça) com a sala tendo escala só na segunda.
    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($own, $room, '09:00', day: '2026-10-20'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'resource_schedule');
});

it('faixas de escala que se encostam valem como uma só', function () {
    $room = sscOwnRoom($this, [['08:00', '12:00'], ['12:00', '18:00']]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($room->facility, $room, '11:30'))
        ->assertOk();

    // Intervalo de almoço real (12:00–13:00 fora): a janela que o atravessa não cabe.
    $gap = sscOwnRoom($this, [['08:00', '12:00'], ['13:00', '18:00']]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($gap->facility, $gap, '11:30'))
        ->assertStatus(409);
});

it('bloqueio no MEIO da janela conflita; bloqueio que só encosta não', function () {
    $room = sscOwnRoom($this);

    ResourceBlock::create([
        'resource_id' => $room->clinic_resource_id, 'starts_at' => SSC_DAY . ' 09:30:00', 'ends_at' => SSC_DAY . ' 09:45:00',
        'type'        => 'meeting', 'reason' => 'Reunião',
    ]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($room->facility, $room, '09:00'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'resource_block');

    // 08:30–09:30 termina quando o bloqueio começa; 09:45–10:45 começa quando termina.
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($room->facility, $room, '08:30'))->assertOk();
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($room->facility, $room, '09:45'))->assertOk();
});

it('sala própria sem nenhuma faixa de escala não tem restrição de horário', function () {
    $room = sscOwnRoom($this, []);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($room->facility, $room, '22:00', day: '2026-10-24'))
        ->assertOk();
});

it('sala própria ligada a recurso inativo não agenda', function () {
    $room = sscOwnRoom($this);
    ClinicResource::query()->whereKey($room->clinic_resource_id)->update(['active' => false]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($room->facility, $room, '09:00'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'resource_inactive');
});

// ── Local sem salas e sala de outro local ───────────────────────────────────

it('local sem salas aceita agendar sem sala; local com salas exige sala do próprio local', function () {
    $bare = sscFacility($this->w, 'Hospital sem salas cadastradas');

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $noRoom = sscCase($this->w)), sscWindow($bare, null))
        ->assertOk();

    expect($noRoom->refresh()->surgery_room_id)->toBeNull();

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case = sscCase($this->w)), sscWindow($this->hosp, null))
        ->assertStatus(422);

    $other = sscRoom($bare, 'Sala do outro hospital');

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $other))
        ->assertStatus(422);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized);
});

it('sem sala, cirurgião e paciente continuam disputando a janela', function () {
    $bare    = sscFacility($this->w, 'Hospital sem salas');
    $surgeon = createDoctorForEntity($this->w->entity);
    sscSchedule($this->w, sscCase($this->w, ['surgeon' => $surgeon]), $bare, null);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w, ['surgeon' => $surgeon])), sscWindow($bare, null, '09:30'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'surgeon');
});

// ── Consultas do cirurgião ──────────────────────────────────────────────────

function sscConsult(object $w, Doctor $doctor, string $at, ScheduleSituation $situation = ScheduleSituation::Scheduled): Schedule
{
    return Schedule::create([
        'entity_id' => $w->entity->id, 'doctor_id' => $doctor->id, 'full_name' => 'PACIENTE CONSULTA',
        'date_time' => $at, 'situation' => $situation->value, 'active' => true,
    ]);
}

it('consulta ativa do cirurgião na janela bloqueia a cirurgia e é devolvida na lista', function () {
    $surgeon = createDoctorForEntity($this->w->entity);
    $surgeon->update(['schedule_interval' => 30]);
    sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00');

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w, ['surgeon' => $surgeon])), sscWindow($this->hosp, $this->room1, '09:00'))
        ->assertStatus(409)
        ->assertJsonPath('context.conflicts.0.kind', 'consultation');
});

it('consulta que termina quando a cirurgia começa, cancelada ou já atendida não bloqueiam', function () {
    $surgeon = createDoctorForEntity($this->w->entity);
    $surgeon->update(['schedule_interval' => 30]);

    sscConsult($this->w, $surgeon, SSC_DAY . ' 08:30:00');                                         // 08:30–09:00: encosta
    sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00', ScheduleSituation::Cancelled);
    sscConsult($this->w, $surgeon, SSC_DAY . ' 09:00:00', ScheduleSituation::Attended);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w, ['surgeon' => $surgeon])), sscWindow($this->hosp, $this->room1, '09:00'))
        ->assertOk();
});

// ── Reagendar e liberar ─────────────────────────────────────────────────────

it('reagenda: nova janela e sala, sem conflitar com a própria janela antiga', function () {
    $case = sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1, '09:00', 60);

    // 09:30 sobrepõe a própria janela antiga: não é conflito.
    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.schedule.update', $case), sscWindow($this->hosp, $this->room2, '09:30') + ['expected_revision' => $case->revision])
        ->assertOk();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Scheduled)
        ->and($case->scheduled_start->format('H:i'))->toBe('09:30')
        ->and((string) $case->surgery_room_id)->toBe((string) $this->room2->id);
});

it('reagendar para a janela atual não faz nada (idempotente, sem histórico novo)', function () {
    $case    = sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1);
    $history = count($case->status_history);
    $rev     = $case->revision;

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.schedule.update', $case), sscWindow($this->hosp, $this->room1))
        ->assertOk();

    expect(count($case->refresh()->status_history))->toBe($history)->and($case->revision)->toBe($rev);
});

it('reagendar para janela em conflito mantém a janela antiga', function () {
    sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room2, '14:00');
    $case = sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1, '09:00');

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.schedule.update', $case), sscWindow($this->hosp, $this->room2, '14:30'))
        ->assertStatus(409);

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Scheduled)
        ->and($case->scheduled_start->format('H:i'))->toBe('09:00')
        ->and((string) $case->surgery_room_id)->toBe((string) $this->room1->id);
});

it('reagendar exige caso agendado', function () {
    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.schedule.update', sscCase($this->w)), sscWindow($this->hosp, $this->room1))
        ->assertStatus(409);
});

it('libera a vaga: volta a autorizado, janela limpa; liberar de novo é idempotente', function () {
    $case = sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1);

    surgeryAs($this->w, 'secretary')
        ->deleteJson(route('panel.surgery.schedule.destroy', $case), ['reason' => 'Paciente remarcou', 'expected_revision' => $case->revision])
        ->assertOk();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->scheduled_start)->toBeNull()->and($case->scheduled_end)->toBeNull()->and($case->surgery_room_id)->toBeNull();

    surgeryAs($this->w, 'secretary')->deleteJson(route('panel.surgery.schedule.destroy', $case))->assertOk();

    // A vaga liberada volta a ser livre para outro caso.
    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($this->hosp, $this->room1))
        ->assertOk();
});

it('liberar caso parcialmente autorizado volta a parcialmente autorizado', function () {
    $case = sscCase($this->w, [], null);
    $case = SurgicalCase::query()->whereKey($case->id)->firstOrFail();
    $case->forceFill(['status' => SurgicalCaseStatus::PartiallyAuthorized, 'authorization_status' => AuthorizationStatus::PartiallyAuthorized])->save();

    sscSchedule($this->w, $case, $this->hosp, $this->room1);
    app(SurgeryScheduleService::class)->release($case->refresh());

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::PartiallyAuthorized);
});

it('liberar com a autorização vencida leva a aguardando autorização (renovar)', function () {
    $case = sscSchedule($this->w, sscCase($this->w, [], ['type' => 'authorized', 'authorization_number' => 'V-1', 'valid_until' => '2026-10-22']), $this->hosp, $this->room1);

    $this->travelTo(Carbon::parse('2026-10-23 06:00:00'));
    app(SurgeryAuthorizationService::class)->expireDue();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled)->and($case->authorization_status)->toBe(AuthorizationStatus::Expired);

    app(SurgeryScheduleService::class)->release($case);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)->and($case->scheduled_start)->toBeNull();
});

// ── Perfis e isolamento ─────────────────────────────────────────────────────

it('financeiro não agenda; médico fora do caso não agenda; outra clínica é 404', function () {
    $case = sscCase($this->w);

    surgeryAs($this->w, 'financial')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1))
        ->assertForbidden();

    // O médico do mundo não é cirurgião nem da equipe deste caso.
    surgeryAs($this->w, 'doctor')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1))
        ->assertForbidden();

    $foreign = surgeryWorld();
    surgeryAs($foreign, 'admin')
        ->postJson(route('panel.surgery.schedule.store', $case), sscWindow($this->hosp, $this->room1))
        ->assertNotFound();

    surgeryAs($foreign, 'admin')->postJson(route('panel.surgery.schedule.check', $case), sscWindow($this->hosp, $this->room1))->assertNotFound();
    surgeryAs($foreign, 'admin')->deleteJson(route('panel.surgery.schedule.destroy', $case))->assertNotFound();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized);
});

it('local e sala de outra clínica não servem (422)', function () {
    $foreign     = surgeryWorld();
    $foreignHosp = sscFacility($foreign, 'Hospital de fora');
    $foreignRoom = sscRoom($foreignHosp, 'Sala de fora');

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($foreignHosp, $foreignRoom))
        ->assertStatus(422);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.schedule.store', sscCase($this->w)), sscWindow($this->hosp, $foreignRoom))
        ->assertStatus(422);
});

// ── A Agenda de consultas respeita a cirurgia ───────────────────────────────

function sscOperating(object $test, ?Doctor $surgeon = null): Doctor
{
    $surgeon ??= createDoctorForEntity($test->w->entity);
    $surgeon->update(['schedule_interval' => 30]);
    sscSchedule($test->w, sscCase($test->w, ['surgeon' => $surgeon]), $test->hosp, $test->room1, '09:00', 60);

    return $surgeon;
}

it('consulta e encaixe do cirurgião dentro da cirurgia são recusados pela validação da Agenda', function () {
    $surgeon = sscOperating($this);
    $service = app(ScheduleService::class);

    $inside = Carbon::parse(SSC_DAY . ' 09:30');
    $before = Carbon::parse(SSC_DAY . ' 08:30'); // 08:30–09:00: encosta, livre
    $after  = Carbon::parse(SSC_DAY . ' 10:00');

    $expected = __('surgery_schedule.agenda_conflict', ['start' => '19/10/2026 09:00', 'end' => '10:00']);

    expect($service->validateSlot((string) $surgeon->id, $inside))->toContain($expected)
        ->and($service->validateFitIn((string) $surgeon->id, $inside))->toContain($expected)
        ->and($service->validateSlot((string) $surgeon->id, $before))->not->toContain($expected)
        ->and($service->validateSlot((string) $surgeon->id, $after))->not->toContain($expected);
});

it('consulta que começa antes da cirurgia mas invade a janela também é recusada', function () {
    $surgeon = sscOperating($this);

    // 08:45 com intervalo de 30 min vai até 09:15, dentro da cirurgia das 09:00.
    expect(app(ScheduleService::class)->validateSlot((string) $surgeon->id, Carbon::parse(SSC_DAY . ' 08:45')))
        ->toContain(__('surgery_schedule.agenda_conflict', ['start' => '19/10/2026 09:00', 'end' => '10:00']));
});

it('médico da equipe também fica indisponível na Agenda', function () {
    $assistant = createDoctorForEntity($this->w->entity);
    $assistant->update(['schedule_interval' => 30]);
    sscSchedule($this->w, sscCase($this->w, ['team' => [['role' => 'assistant', 'doctor_id' => (string) $assistant->id]]]), $this->hosp, $this->room1);

    expect(app(ScheduleService::class)->surgeryOverlapError((string) $assistant->id, Carbon::parse(SSC_DAY . ' 09:30')))->not->toBeNull();
});

it('cirurgia de outro médico não bloqueia a Agenda deste', function () {
    sscOperating($this);
    $free = createDoctorForEntity($this->w->entity);

    expect(app(ScheduleService::class)->surgeryOverlapError((string) $free->id, Carbon::parse(SSC_DAY . ' 09:30')))->toBeNull();
});

it('a trava sob transação recusa a consulta que a validação deixou passar', function () {
    $surgeon = sscOperating($this);

    expect(fn () => DB::transaction(fn () => app(ScheduleService::class)->assertDoctorFreeOfSurgery((string) $surgeon->id, Carbon::parse(SSC_DAY . ' 09:15'))))
        ->toThrow(ScheduleSlotUnavailableException::class);

    DB::transaction(fn () => app(ScheduleService::class)->assertDoctorFreeOfSurgery((string) $surgeon->id, Carbon::parse(SSC_DAY . ' 10:00')));
});

it('criar consulta pelo HTTP no horário da cirurgia dá 422 com a mensagem e nada é gravado', function () {
    $surgeon = sscOperating($this);

    $response = surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.schedules.store'), [
            'doctor_id' => (string) $surgeon->id, 'full_name' => 'PACIENTE NOVO', 'date_time' => SSC_DAY . ' 09:30',
        ]);

    $response->assertUnprocessable();
    expect(json_encode($response->json()))->toContain('cirurgia agendada');
    expect(Schedule::query()->where('doctor_id', $surgeon->id)->count())->toBe(0);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.schedules.store'), [
            'doctor_id' => (string) $surgeon->id, 'full_name' => 'PACIENTE NOVO', 'date_time' => SSC_DAY . ' 10:00',
        ])
        ->assertCreated();
});

it('o seletor de horários marca indisponível o que cai na cirurgia', function () {
    $surgeon = sscOperating($this);

    foreach ([1 => ['08:00', '12:00']] as $dow => [$from, $to]) {
        DoctorWorkSchedule::create(['doctor_id' => $surgeon->id, 'day_of_week' => $dow, 'starts_at' => "{$from}:00", 'ends_at' => "{$to}:00"]);
    }

    $slots = surgeryAs($this->w, 'secretary')
        ->getJson(route('panel.schedules.slots', ['doctor_id' => (string) $surgeon->id, 'date' => SSC_DAY]))
        ->assertOk()
        ->json('slots');

    $byTime = collect($slots)->pluck('available', 'time');

    expect($byTime['08:30'])->toBeTrue()->and($byTime['09:00'])->toBeFalse()->and($byTime['09:30'])->toBeFalse()->and($byTime['10:00'])->toBeTrue();
});

it('o calendário de vagas desconta a cirurgia e se atualiza ao agendar e ao liberar', function () {
    $surgeon = createDoctorForEntity($this->w->entity);
    $surgeon->update(['schedule_interval' => 30]);
    DoctorWorkSchedule::create(['doctor_id' => $surgeon->id, 'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);

    $availability = app(ScheduleAvailabilityService::class);
    $month        = Carbon::parse('2026-10-01');
    $free         = fn () => $availability->month((string) $this->w->entity->id, $month, (string) $surgeon->id)['doctor_days'][(string) $surgeon->id][SSC_DAY]['free'];

    expect($free())->toBe(4);

    $case = sscSchedule($this->w, sscCase($this->w, ['surgeon' => $surgeon]), $this->hosp, $this->room1, '09:00', 60);

    expect($free())->toBe(2);

    app(SurgeryScheduleService::class)->release($case);

    expect($free())->toBe(4);
});

it('o calendário de vagas desconta pelo intervalo inteiro: cirurgia entre dois horários da grade tira os que ela encosta', function () {
    $surgeon = createDoctorForEntity($this->w->entity);
    $surgeon->update(['schedule_interval' => 15]);
    DoctorWorkSchedule::create(['doctor_id' => $surgeon->id, 'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);

    $availability = app(ScheduleAvailabilityService::class);
    $day          = fn () => $availability->month((string) $this->w->entity->id, Carbon::parse('2026-10-01'), (string) $surgeon->id)['doctor_days'][(string) $surgeon->id][SSC_DAY];

    expect($day())->toMatchArray(['capacity' => 8, 'free' => 8]);

    // 09:10–09:40: o início cai ENTRE 09:00 e 09:15 da grade.
    sscSchedule($this->w, sscCase($this->w, ['surgeon' => $surgeon]), $this->hosp, $this->room1, '09:10', 30);

    $picker = collect(app(ScheduleService::class)->getAvailableSlots($surgeon, Carbon::parse(SSC_DAY)))->pluck('available', 'time');

    // 09:00 (vai até 09:15), 09:15 e 09:30 (vai até 09:45) invadem a cirurgia; 09:45 já é depois dela.
    expect($picker->only(['09:00', '09:15', '09:30'])->every(fn ($available) => $available === false))->toBeTrue()
        ->and($picker['09:45'])->toBeTrue()
        ->and($day())->toMatchArray(['capacity' => 5, 'free' => 5])            // antes do conserto: 09:00 seguia livre (6)
        ->and($picker->filter()->count())->toBe($day()['free']);               // o calendário e o seletor contam o mesmo

    // A validação de agendamento recusa o horário que o calendário agora não conta.
    expect(app(ScheduleService::class)->surgeryOverlapError((string) $surgeon->id, Carbon::parse(SSC_DAY . ' 09:00')))->not->toBeNull()
        ->and(app(ScheduleService::class)->surgeryOverlapError((string) $surgeon->id, Carbon::parse(SSC_DAY . ' 09:45')))->toBeNull();
});

it('cirurgia que termina quando o horário começa, ou que começa quando ele termina, não o tira do calendário', function () {
    $surgeon = createDoctorForEntity($this->w->entity);
    $surgeon->update(['schedule_interval' => 15]);
    DoctorWorkSchedule::create(['doctor_id' => $surgeon->id, 'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);

    sscSchedule($this->w, sscCase($this->w, ['surgeon' => $surgeon]), $this->hosp, $this->room1, '09:00', 30);   // 09:00–09:30

    $day = app(ScheduleAvailabilityService::class)->month((string) $this->w->entity->id, Carbon::parse('2026-10-01'), (string) $surgeon->id)['doctor_days'][(string) $surgeon->id][SSC_DAY];

    // 09:00 e 09:15 saem; 08:45 (termina 09:00) e 09:30 (começa 09:30) ficam.
    expect($day)->toMatchArray(['capacity' => 6, 'free' => 6]);
});

// ── Reativar e remarcar em lote não furam a cirurgia ────────────────────────

/** Mesma conferência de cirurgia que criar e remarcar fazem, agora também para quem VOLTA à grade. */
function sscSituate(object $test, Schedule $schedule, ScheduleSituation $to): TestResponse
{
    return surgeryAs($test->w, 'secretary')->patchJson(route('panel.schedules.situation', $schedule), ['situation' => $to->value]);
}

it('reativar consulta cancelada no horário de uma cirurgia agendada é recusado (422) e nada muda', function () {
    $surgeon = sscOperating($this);                                                                     // cirurgia 09:00–10:00
    $consult = sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00', ScheduleSituation::Cancelled);     // a cirurgia ocupou o horário depois

    foreach ([ScheduleSituation::Scheduled, ScheduleSituation::Confirmed, ScheduleSituation::Waiting] as $to) {
        $response = sscSituate($this, $consult, $to)->assertUnprocessable();

        expect(json_encode($response->json()))->toContain('cirurgia agendada');
    }

    expect($consult->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and(ScheduleSituationLog::query()->where('schedule_id', $consult->id)->count())->toBe(0);
});

it('faltou e atendido também não voltam para dentro da cirurgia', function (ScheduleSituation $from) {
    $surgeon = sscOperating($this);
    $consult = sscConsult($this->w, $surgeon, SSC_DAY . ' 09:00:00', $from);

    sscSituate($this, $consult, ScheduleSituation::Scheduled)->assertUnprocessable();

    expect($consult->refresh()->situation)->toBe($from);
})->with([ScheduleSituation::NoShow, ScheduleSituation::Attended]);

it('reativar fora da cirurgia segue funcionando, e mexer em consulta que já está ativa não é reativar', function () {
    $surgeon  = sscOperating($this);
    $touching = sscConsult($this->w, $surgeon, SSC_DAY . ' 10:00:00', ScheduleSituation::Cancelled); // começa quando a cirurgia termina

    sscSituate($this, $touching, ScheduleSituation::Scheduled)->assertOk();

    expect($touching->refresh()->situation)->toBe(ScheduleSituation::Scheduled);

    // Já estava ativa no horário (anterior à cirurgia): confirmar e cancelar continuam possíveis.
    $inside = sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00');

    sscSituate($this, $inside, ScheduleSituation::Confirmed)->assertOk();
    sscSituate($this, $inside, ScheduleSituation::Cancelled)->assertOk();

    expect($inside->refresh()->situation)->toBe(ScheduleSituation::Cancelled);
});

it('liberada a vaga da cirurgia, a consulta cancelada daquele horário pode voltar', function () {
    $surgeon = createDoctorForEntity($this->w->entity);
    $surgeon->update(['schedule_interval' => 30]);
    $case    = sscSchedule($this->w, sscCase($this->w, ['surgeon' => $surgeon]), $this->hosp, $this->room1, '09:00', 60);
    $consult = sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00', ScheduleSituation::Cancelled);

    sscSituate($this, $consult, ScheduleSituation::Scheduled)->assertUnprocessable();

    app(SurgeryScheduleService::class)->release($case);

    sscSituate($this, $consult, ScheduleSituation::Scheduled)->assertOk();
});

it('o atendimento do pronto atendimento reaberto não passa pela cirurgia (nasce fora da grade)', function () {
    $surgeon = sscOperating($this);
    $urgent  = sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00', ScheduleSituation::Cancelled);
    $urgent->forceFill(['origin' => Schedule::ORIGIN_URGENT_CARE])->save();

    sscSituate($this, $urgent, ScheduleSituation::Scheduled)->assertOk();
});

it('o formulário da agenda com a situação pedida também é barrado, e nenhum campo é gravado', function () {
    $surgeon = sscOperating($this);
    $consult = sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00', ScheduleSituation::Cancelled);

    $response = surgeryAs($this->w, 'secretary')->putJson(route('panel.schedules.update', $consult), [
        'doctor_id' => (string) $surgeon->id, 'full_name' => 'NOME EDITADO', 'date_time' => SSC_DAY . ' 09:30',
        'situation' => ScheduleSituation::Scheduled->value,
    ])->assertUnprocessable();

    expect(json_encode($response->json()))->toContain('cirurgia agendada')
        ->and($consult->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($consult->full_name)->toBe('PACIENTE CONSULTA');
});

it('em lote, a linha que reativaria dentro da cirurgia é recusada com o motivo e as demais seguem', function () {
    $surgeon = sscOperating($this);
    $inside  = sscConsult($this->w, $surgeon, SSC_DAY . ' 09:30:00', ScheduleSituation::Cancelled);
    $free    = sscConsult($this->w, $surgeon, SSC_DAY . ' 10:30:00', ScheduleSituation::Cancelled);

    $response = surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.schedules.bulk-update'), ['ids' => [(string) $inside->id, (string) $free->id], 'situation' => ScheduleSituation::Scheduled->value])
        ->assertOk();

    $reason = $response->json('blocked.0.message');

    expect($response->json('updated'))->toBe(1)
        ->and($response->json('skipped'))->toBe(1)
        ->and($response->json('blocked.0.id'))->toBe((string) $inside->id)
        ->and($response->json('blocked.0.reason'))->toBe('slot_unavailable')
        ->and($reason)->toContain($inside->code)->toContain('cirurgia agendada')
        ->and($response->json('message'))->toContain($reason)
        ->and($response->json('message'))->not->toContain(__('schedules.bulk_skipped_cash', ['blocked' => 1]))
        ->and($inside->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($free->refresh()->situation)->toBe(ScheduleSituation::Scheduled);
});

it('a remarcação em lote recusa o horário em cirurgia e move as demais consultas', function () {
    $surgeon = sscOperating($this);                                                // segunda 19/10, 09:00–10:00
    $inside  = sscConsult($this->w, $surgeon, '2026-10-26 09:30:00');             // vai cair às 09:30 do dia 19
    $free    = sscConsult($this->w, $surgeon, '2026-10-26 10:30:00');

    $response = surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.schedules.bulk-reschedule'), ['ids' => [(string) $inside->id, (string) $free->id], 'date' => SSC_DAY])
        ->assertOk();

    expect($response->json('updated'))->toBe(1)
        ->and($response->json('skipped'))->toBe(1)
        ->and($response->json('blocked.0.message'))->toContain('cirurgia agendada')
        ->and($inside->refresh()->date_time->format('Y-m-d H:i'))->toBe('2026-10-26 09:30')
        ->and($free->refresh()->date_time->format('Y-m-d H:i'))->toBe(SSC_DAY . ' 10:30');
});

it('a remarcação em lote reconfere a cirurgia SOB a trava, mesmo que a validação (sem trava) tenha deixado passar', function () {
    $surgeon = sscOperating($this);
    $consult = sscConsult($this->w, $surgeon, '2026-10-26 09:30:00');

    // A validação leu "livre" e a cirurgia foi comitada antes da gravação: só a
    // reconferência dentro da transação, sob a trava, impede a consulta de cair nela.
    $service = Mockery::mock(ScheduleService::class, [app(ExternalExamImportService::class)])->makePartial();
    $service->shouldReceive('validateFor')->andReturn([]);

    $result = $service->bulkReschedule([(string) $consult->id], SSC_DAY, '09:30', (string) $this->w->entity->id, (string) $this->w->eus['secretary']->id);

    expect($result['updated'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['blocked'][0]['message'])->toContain('cirurgia agendada')
        ->and($consult->refresh()->date_time->format('Y-m-d H:i'))->toBe('2026-10-26 09:30');
});

// ── Tela da agenda cirúrgica ────────────────────────────────────────────────

it('a agenda do dia entrega colunas, casos e a fila dos autorizados', function () {
    $scheduled = sscSchedule($this->w, sscCase($this->w, ['title' => 'Catarata OD']), $this->hosp, $this->room1);
    sscCase($this->w, ['title' => 'Esperando vaga', 'urgency' => 'urgent']);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.agenda', ['date' => SSC_DAY]))
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Surgery/Agenda')
            ->where('view', 'day')
            ->where('date', SSC_DAY)
            ->has('cases', 1)
            ->where('cases.0.id', (string) $scheduled->id)
            ->where('cases.0.room_id', (string) $this->room1->id)
            ->where('cases.0.checklist', [false, false, false])
            ->has('queue', 1)
            ->where('queue.0.title', 'Esperando vaga')
            ->has('rooms', 2)
            ->has('options.facilities', 1)
            ->where('can.manage', true));
});

it('a visão semanal cobre segunda a domingo e o filtro de local recorta', function () {
    sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1);
    $other     = sscFacility($this->w, 'Clínica', 'own_clinic');
    $otherRoom = sscRoom($other, 'Sala da clínica');
    sscSchedule($this->w, sscCase($this->w), $other, $otherRoom, '11:00');

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.agenda', ['date' => '2026-10-21', 'view' => 'week']))
        ->assertInertia(fn ($page) => $page
            ->where('view', 'week')
            ->where('range.from', '2026-10-19')
            ->where('range.to', '2026-10-25')
            ->has('cases', 2));

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.agenda', ['date' => SSC_DAY, 'facility' => (string) $other->id]))
        ->assertInertia(fn ($page) => $page->has('cases', 1)->where('cases.0.facility_id', (string) $other->id));
});

it('data inválida cai em hoje; filtro com lixo é ignorado', function () {
    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.agenda', ['date' => '31/02/2026', 'facility' => 'x', 'surgeon' => "'; drop"]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('date', '2026-10-15')->where('filters.facility', null)->where('filters.surgeon', null));
});

it('o médico vê só as cirurgias em que opera; o financeiro não abre a agenda', function () {
    $mine = sscSchedule($this->w, sscCase($this->w, ['surgeon' => $this->w->doctor]), $this->hosp, $this->room1);
    sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room2);
    sscCase($this->w, ['title' => 'Do colega', 'surgeon' => createDoctorForEntity($this->w->entity)]);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.agenda', ['date' => SSC_DAY]))
        ->assertInertia(fn ($page) => $page->has('cases', 1)->where('cases.0.id', (string) $mine->id)->has('queue', 0));

    surgeryAs($this->w, 'financial')->get(route('panel.surgery.agenda'))->assertForbidden();
});

it('a agenda não vaza casos de outra clínica', function () {
    $foreign = surgeryWorld();
    $fh      = sscFacility($foreign);
    sscSchedule($foreign, sscCase($foreign), $fh, sscRoom($fh));

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.agenda', ['date' => SSC_DAY]))
        ->assertInertia(fn ($page) => $page->has('cases', 0)->has('rooms', 2));
});

it('o mapa cirúrgico do dia sai em PDF e valida a data', function () {
    sscSchedule($this->w, sscCase($this->w), $this->hosp, $this->room1);

    $response = surgeryAs($this->w, 'secretary')->get(route('panel.surgery.agenda.map', ['date' => SSC_DAY]));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');

    surgeryAs($this->w, 'secretary')->getJson(route('panel.surgery.agenda.map', ['date' => 'amanhã']))->assertStatus(422);
    surgeryAs($this->w, 'financial')->get(route('panel.surgery.agenda.map', ['date' => SSC_DAY]))->assertForbidden();
});

it('a aba Agenda aparece no caso autorizado com as ações certas', function () {
    $case = sscCase($this->w);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.schedule.can_schedule', true)
            ->where('sections.schedule.can_reschedule', false)
            ->where('sections.schedule.window', null)
            ->has('sections.schedule.facilities.0.rooms', 2));

    sscSchedule($this->w, $case, $this->hosp, $this->room1);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.schedule.can_schedule', false)
            ->where('sections.schedule.can_release', true)
            ->where('sections.schedule.window.room', 'Sala 1'));
});

// ── Travas: ordem e conjunto de recursos ────────────────────────────────────

/** Registra, na ordem, os advisory locks e a trava FOR UPDATE da linha do caso. @return array{log: list<array{kind: string, key?: int}>} */
function sscWatchLocks(): object
{
    $watch      = new stdClass();
    $watch->log = [];

    DB::listen(function ($query) use ($watch) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $watch->log[] = ['kind' => 'advisory', 'key' => (int) $query->bindings[0]];
        } elseif (str_contains($sql, 'from "surgical_cases"') && str_contains($sql, 'for update')) {
            $watch->log[] = ['kind' => 'case_row'];
        }
    });

    return $watch;
}

it('toma um advisory lock por recurso em ordem crescente e só então trava a linha do caso', function () {
    $surgeon   = createDoctorForEntity($this->w->entity);
    $assistant = createDoctorForEntity($this->w->entity);
    $case      = sscCase($this->w, [
        'surgeon' => $surgeon,
        'team'    => [['role' => 'assistant', 'doctor_id' => (string) $assistant->id]],
    ]);

    $watch = sscWatchLocks();
    sscSchedule($this->w, $case, $this->hosp, $this->room1);

    $kinds    = array_column($watch->log, 'kind');
    $advisory = array_values(array_filter($watch->log, fn ($e) => $e['kind'] === 'advisory'));
    $keys     = array_column($advisory, 'key');

    $expected = [
        SurgeryScheduleService::lockKey('patient', (string) $case->patient_id),
        SurgeryScheduleService::lockKey('doctor', (string) $surgeon->id),
        SurgeryScheduleService::lockKey('doctor', (string) $assistant->id),
        SurgeryScheduleService::lockKey('room', (string) $this->room1->id),
    ];
    sort($expected, SORT_NUMERIC);

    expect($keys)->toBe($expected)
        ->and($kinds[count($kinds) - 5] ?? null)->toBe('advisory')
        ->and(array_search('case_row', $kinds, true))->toBe(4); // as 4 travas de recurso vêm antes da linha do caso
});

it('equipe que cresce entre a leitura e a trava do caso desfaz a tentativa (solta os locks) e refaz', function () {
    $busy = createDoctorForEntity($this->w->entity);
    $case = sscCase($this->w);

    $watch    = sscWatchLocks();
    $injected = false;

    // Logo depois da trava da linha do caso, "outra requisição" põe um médico na equipe — só na
    // 1ª tentativa; o SAVEPOINT desfeito leva o insert junto, então a 2ª tentativa fecha limpa.
    DB::listen(function ($query) use (&$injected, $case, $busy) {
        $sql = strtolower($query->sql);

        if (! $injected && str_contains($sql, 'from "surgical_cases"') && str_contains($sql, 'for update')) {
            $injected = true;
            SurgicalTeamMember::query()->create([
                'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'role' => 'assistant',
                'doctor_id' => $busy->id, 'name' => 'ASSISTENTE TARDIO', 'position' => 0,
            ]);
        }
    });

    $scheduled = sscSchedule($this->w, $case, $this->hosp, $this->room1, '09:30');

    $rounds = count(array_filter($watch->log, fn ($x) => $x['kind'] === 'case_row'));

    expect($scheduled->status)->toBe(SurgicalCaseStatus::Scheduled)
        ->and($rounds)->toBe(2); // a linha do caso foi travada duas vezes: tentativa desfeita + refeita
});

it('se a equipe continua mudando a cada tentativa, desiste depois de 3 com 409 e nada é gravado', function () {
    $case    = sscCase($this->w);
    $doctors = [createDoctorForEntity($this->w->entity), createDoctorForEntity($this->w->entity), createDoctorForEntity($this->w->entity)];
    $n       = 0;

    DB::listen(function ($query) use (&$n, $case, $doctors) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'from "surgical_cases"') && str_contains($sql, 'for update') && $n < 3) {
            SurgicalTeamMember::query()->create([
                'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'role' => 'assistant',
                'doctor_id' => $doctors[$n]->id, 'name' => 'ASSISTENTE ' . $n, 'position' => $n,
            ]);
            $n++;
        }
    });

    expect(fn () => sscSchedule($this->w, $case, $this->hosp, $this->room1, '09:30'))
        ->toThrow(SurgeryRuleException::class, __('surgery_schedule.errors.changed_concurrently'));

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized)->and($case->scheduled_start)->toBeNull();
});

// ── Revisão adversarial: equipe, travas de local/sala, sigilo e idioma ──────

it('equipe de cirurgia AGENDADA não troca de médicos; nome avulso e a mesma equipe passam', function () {
    $assistant = createDoctorForEntity($this->w->entity);
    $case      = sscSchedule($this->w, sscCase($this->w, ['team' => [['role' => 'assistant', 'doctor_id' => (string) $assistant->id]]]), $this->hosp, $this->room1);
    $other     = createDoctorForEntity($this->w->entity);

    expect(fn () => app(SurgicalCaseService::class)->update($case, ['team' => [['role' => 'assistant', 'doctor_id' => (string) $other->id]]]))
        ->toThrow(SurgeryRuleException::class, __('surgery_schedule.errors.team_locked_while_scheduled'));

    // Mesma equipe + um instrumentador sem cadastro de médico: sem efeito na disputa da janela.
    app(SurgicalCaseService::class)->update($case, ['team' => [
        ['role' => 'assistant', 'doctor_id' => (string) $assistant->id],
        ['role' => 'scrub_nurse', 'name' => 'ENFERMEIRA JOANA'],
    ]]);

    expect($case->refresh()->team)->toHaveCount(2);

    // Fora do estado agendado a equipe segue livre.
    app(SurgeryScheduleService::class)->release($case);
    app(SurgicalCaseService::class)->update($case->refresh(), ['team' => [['role' => 'assistant', 'doctor_id' => (string) $other->id]]]);

    expect($case->refresh()->team->first()->doctor_id)->toBe((string) $other->id);
});

it('agendar trava local e sala com FOR SHARE (apagar/desativar espera e vê o caso agendado)', function () {
    $case    = sscCase($this->w);
    $queries = [];

    DB::listen(function ($q) use (&$queries) {
        if (str_contains(strtolower($q->sql), 'for share')) {
            $queries[] = strtolower($q->sql);
        }
    });

    sscSchedule($this->w, $case, $this->hosp, $this->room1);

    expect(collect($queries)->contains(fn ($sql) => str_contains($sql, '"surgery_facilities"')))->toBeTrue()
        ->and(collect($queries)->contains(fn ($sql) => str_contains($sql, '"surgery_rooms"')))->toBeTrue();
});

it('o médico só vê código/título/horário dos conflitos de casos em que atua; secretária vê tudo', function () {
    $mine  = sscCase($this->w, ['surgeon' => $this->w->doctor]);
    $their = sscSchedule($this->w, sscCase($this->w, ['title' => 'Pterígio — MARIA DA SILVA']), $this->hosp, $this->room1);

    $masked = surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.schedule.check', $mine), sscWindow($this->hosp, $this->room1, '09:30'))
        ->assertOk()->assertJsonPath('conflicts.0.kind', 'room')->assertJsonPath('conflicts.0.case_code', null)->assertJsonPath('conflicts.0.title', null);

    expect($masked->json('conflicts.0.text'))->toBe(__('surgery_schedule.conflict_masked.room', ['label' => 'Sala 1']))
        ->and($masked->getContent())->not->toContain('MARIA')->not->toContain($their->code);

    // O 409 do envio também sai mascarado para o médico.
    $blocked = surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.schedule.store', $mine), sscWindow($this->hosp, $this->room1, '09:30'))->assertStatus(409);
    expect($blocked->getContent())->not->toContain('MARIA')->not->toContain($their->code);

    // Secretária mantém o detalhe.
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.schedule.check', $mine), sscWindow($this->hosp, $this->room1, '09:30'))
        ->assertJsonPath('conflicts.0.case_code', $their->code);
});

it('mensagens de data seguem o idioma (en: mm/dd/aaaa)', function () {
    $case = sscCase($this->w, [], ['type' => 'authorized', 'authorization_number' => 'A-1', 'valid_until' => '2026-10-17']);

    app()->setLocale('en');

    expect(fn () => sscSchedule($this->w, $case, $this->hosp, $this->room1))
        ->toThrow(SurgeryRuleException::class, 'valid until 10/17/2026');
});
