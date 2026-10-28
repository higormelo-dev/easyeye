<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, Schedule, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fluxo Agenda ↔ Prontuário (buildFormProps → props.scheduleFlow).
 *
 * O front usa esse prop para: marcar "Em consulta" ao abrir o prontuário e
 * perguntar o destino do paciente ao sair (Finalizar/Dilatar/Exame/Continuar)
 * — ver ScheduleFlowGuard.vue. Aqui garantimos o contrato do backend.
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $covenant      = Covenant::factory()->create();
    $patientPerson = People::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $patientPerson->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);

    $entityUser   = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $doctorPerson = People::factory()->create();
    $this->doctor = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => $doctorPerson->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->schedule = Schedule::query()->create([
        'entity_id'  => $this->entity->id,
        'doctor_id'  => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'full_name'  => 'PACIENTE FLUXO',
        'date_time'  => now()->addHour(),
        'situation'  => ScheduleSituation::Waiting->value,
        'active'     => true,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('edit de prontuário vinculado a agendamento expõe scheduleFlow (id, situação e URL de transição)', function () {
    $record = MedicalRecord::create([
        'entity_id'   => $this->entity->id,
        'patient_id'  => $this->patient->id,
        'doctor_id'   => $this->doctor->id,
        'schedule_id' => $this->schedule->id,
    ]);

    $response = $this->get(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
    $response->assertOk();

    $flow = $response->viewData('page')['props']['scheduleFlow'];

    expect($flow)->not->toBeNull()
        ->and($flow['id'])->toBe((string) $this->schedule->id)
        ->and($flow['situation'])->toBe(ScheduleSituation::Waiting->value)
        ->and($flow['update_url'])->toContain("/panel/schedules/{$this->schedule->id}/situation");
});

it('create com ?schedule_id= (vindo da Agenda) também expõe scheduleFlow', function () {
    $response = $this->get(route('panel.patients.medicalrecords.create', $this->patient) . '?schedule_id=' . $this->schedule->id);
    $response->assertOk();

    $flow = $response->viewData('page')['props']['scheduleFlow'];

    expect($flow)->not->toBeNull()
        ->and($flow['id'])->toBe((string) $this->schedule->id);
});

it('prontuário sem agendamento vinculado NÃO expõe fluxo', function () {
    $record = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    $response = $this->get(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
    $response->assertOk();

    expect($response->viewData('page')['props']['scheduleFlow'])->toBeNull();
});

it('[SEGURANÇA] schedule_id de OUTRA clínica no querystring não vaza fluxo', function () {
    $otherEntity    = Entity::factory()->create(['is_client' => true]);
    $otherUser      = User::factory()->create();
    $otherEu        = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);
    $otherDocPerson = People::factory()->create();
    $otherDoctor    = Doctor::create([
        'entity_user_id' => $otherEu->id,
        'person_id'      => $otherDocPerson->id,
        'record'         => '99999',
        'color'          => '#00FF00',
        'partner'        => false,
        'active'         => true,
    ]);
    $foreignSchedule = Schedule::query()->create([
        'entity_id' => $otherEntity->id,
        'doctor_id' => $otherDoctor->id,
        'full_name' => 'OUTRA CLINICA',
        'date_time' => now()->addHour(),
        'situation' => ScheduleSituation::Waiting->value,
        'active'    => true,
    ]);

    $response = $this->get(route('panel.patients.medicalrecords.create', $this->patient) . '?schedule_id=' . $foreignSchedule->id);
    $response->assertOk();

    expect($response->viewData('page')['props']['scheduleFlow'])->toBeNull();
});
