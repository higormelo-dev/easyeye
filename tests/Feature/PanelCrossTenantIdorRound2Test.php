<?php

declare(strict_types=1);

/**
 * Regressão de segurança — rodada 2 da auditoria panel.* IDOR: re-verificação
 * adversarial dos veredictos SAFE da rodada 1 achou 1 regressão minha
 * (MedicalRecordsController::ajaxList sem a checagem que os outros métodos já
 * tinham) e a varredura do padrão "ID via request body" achou 2 achados novos
 * (doctor_id sem escopo de entity em MedicalRecordsController::store/update e
 * SchedulesController::reschedule — Doctor não tem entity_id direto, só via
 * entity_user_id -> entity_users.entity_id).
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, Schedule, User, VisitType};

beforeEach(function () {
    $this->entityA = Entity::factory()->create(['is_client' => true]);
    $this->entityB = Entity::factory()->create(['is_client' => true]);

    $this->staffA      = User::factory()->create();
    $this->staffB      = User::factory()->create();
    $this->entityUserA = createEntityUser($this->entityA, $this->staffA, ClientRule::Doctor->value);
    $this->entityUserB = createEntityUser($this->entityB, $this->staffB, ClientRule::Doctor->value);

    $covenant       = Covenant::factory()->create();
    $personA        = People::factory()->create();
    $this->patientA = Patient::create([
        'entity_id'   => $this->entityA->id,
        'person_id'   => $personA->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);

    $doctorPersonA = People::factory()->create();
    $this->doctorA = Doctor::create([
        'entity_user_id' => $this->entityUserA->id,
        'person_id'      => $doctorPersonA->id,
        'record'         => '11111',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $doctorPersonB = People::factory()->create();
    $this->doctorB = Doctor::create([
        'entity_user_id' => $this->entityUserB->id,
        'person_id'      => $doctorPersonB->id,
        'record'         => '22222',
        'color'          => '#00FF00',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->recordA = MedicalRecord::create([
        'patient_id'     => $this->patientA->id,
        'doctor_id'      => $this->doctorA->id,
        'main_complaint' => 'Consulta de rotina',
    ]);
});

function actAsA($test)
{
    return $test->actingAs($test->staffA)->withSession(panelSession($test->entityUserA));
}

// ── MedicalRecordsController::store/update — doctor_id de outra entity ─────

test('store: nao aceita doctor_id de OUTRA entity mesmo com patient_id valido', function () {
    actAsA($this)
        ->post(route('panel.patients.medicalrecords.store', $this->patientA), [
            'doctor_id'      => $this->doctorB->id,
            'main_complaint' => 'Tentativa com medico de outra clinica',
        ])
        ->assertSessionHasErrors('doctor_id');

    expect(MedicalRecord::where('doctor_id', $this->doctorB->id)->count())->toBe(0);
});

test('update: nao aceita trocar o prontuario para doctor_id de OUTRA entity', function () {
    actAsA($this)
        ->put(route('panel.patients.medicalrecords.update', [$this->patientA, $this->recordA]), [
            'doctor_id'      => $this->doctorB->id,
            'main_complaint' => 'Tentativa de troca de medico cross-tenant',
        ])
        ->assertSessionHasErrors('doctor_id');

    expect($this->recordA->fresh()->doctor_id)->toBe($this->doctorA->id);
});

test('store: doctor_id da propria entity continua funcionando normalmente', function () {
    actAsA($this)
        ->post(route('panel.patients.medicalrecords.store', $this->patientA), [
            'doctor_id'      => $this->doctorA->id,
            'main_complaint' => 'Consulta legitima',
        ])
        ->assertRedirect();
});

// ── SchedulesController::reschedule — doctor_id de outra entity ────────────

test('reschedule: nao aceita doctor_id de OUTRA entity', function () {
    $visitType = VisitType::create([
        'entity_id' => $this->entityA->id,
        'code'      => 'CONS',
        'name'      => 'Consulta',
        'active'    => true,
    ]);

    $schedule = Schedule::create([
        'entity_id'          => $this->entityA->id,
        'doctor_id'          => $this->doctorA->id,
        'patient_id'         => $this->patientA->id,
        'covenant_id'        => $this->patientA->covenant_id,
        'visit_id'           => $visitType->id,
        'code'               => 'AGD-TESTE-001',
        'full_name'          => 'Paciente Teste',
        'date_time'          => now()->addDay(),
        'cellphone'          => '11999990000',
        'cellphone_whatsapp' => false,
        'situation'          => ScheduleSituation::Scheduled->value,
        'active'             => true,
    ]);

    actAsA($this)
        ->postJson(route('panel.schedules.reschedule', $schedule), [
            'date_time' => now()->addDays(2)->toDateTimeString(),
            'doctor_id' => $this->doctorB->id,
        ])
        ->assertStatus(422);

    expect(Schedule::where('doctor_id', $this->doctorB->id)->count())->toBe(0);
});

// ── MedicalRecordsController::ajaxList — regressão fechada ─────────────────

test('ajaxlist: staff de OUTRA entity recebe 404 ao listar prontuarios via ajax', function () {
    $this->actingAs($this->staffB)
        ->withSession(panelSession($this->entityUserB))
        ->getJson(route('panel.patients.medicalrecords.ajaxlist', $this->patientA))
        ->assertNotFound();
});

test('ajaxlist: staff da entity correta consegue listar via ajax normalmente', function () {
    actAsA($this)
        ->getJson(route('panel.patients.medicalrecords.ajaxlist', $this->patientA))
        ->assertOk();
});
