<?php

/**
 * Regressão de segurança (achado da auditoria da Fase 1 do Portal do
 * Paciente): PatientsController::editData() não checava entity_id — staff de
 * uma entity conseguia ler PII completo (CPF, endereço, telefone) de paciente
 * de OUTRA entity só sabendo o UUID, porque o route model binding de
 * {patient} não é filtrado por tenant (SubstituteBindings roda antes de
 * tenant.bind). Ver comentário em PatientsController::editData().
 */

use App\Models\{Entity, Patient, People, User};

beforeEach(function () {
    $this->entityA = Entity::factory()->create(['is_client' => true]);
    $this->entityB = Entity::factory()->create(['is_client' => true]);

    $this->person = People::factory()->create(['national_registry' => '12345678900']);

    $this->patientInA = Patient::factory()->create([
        'entity_id' => $this->entityA->id,
        'person_id' => $this->person->id,
    ]);
});

test('staff da entity correta consegue ler edit-data do proprio paciente', function () {
    $staff      = User::factory()->create();
    $entityUser = createEntityUser($this->entityA, $staff, 'admin');

    $this->actingAs($staff)
        ->withSession(panelSession($entityUser))
        ->getJson(route('panel.patients.editData', $this->patientInA))
        ->assertOk()
        ->assertJsonPath('data.national_registry', '12345678900');
});

test('staff de OUTRA entity nao consegue ler edit-data de paciente que nao pertence a ela (404)', function () {
    $staffB      = User::factory()->create();
    $entityUserB = createEntityUser($this->entityB, $staffB, 'admin');

    $this->actingAs($staffB)
        ->withSession(panelSession($entityUserB))
        ->getJson(route('panel.patients.editData', $this->patientInA))
        ->assertNotFound();
});
