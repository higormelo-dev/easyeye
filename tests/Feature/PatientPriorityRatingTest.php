<?php

/**
 * Estrela de prioridade/triagem do paciente (benchmark contra concorrente
 * Ger Exames/iWayBrasil, 18/09/2026) — PatientsController::updatePriority().
 * Mesma checagem de tenant explícita de editData() (route model binding de
 * {patient} sozinho não é filtrado por entidade).
 */

use App\Models\{Entity, Patient, People, User};

beforeEach(function () {
    $this->entityA = Entity::factory()->create(['is_client' => true]);
    $this->entityB = Entity::factory()->create(['is_client' => true]);

    $this->patientInA = Patient::factory()->create([
        'entity_id' => $this->entityA->id,
        'person_id' => People::factory()->create()->id,
    ]);

    $this->staff      = User::factory()->create();
    $this->entityUser = createEntityUser($this->entityA, $this->staff, 'admin');
});

function actingAsPatientStaff($test)
{
    return $test->actingAs($test->staff)->withSession(panelSession($test->entityUser));
}

it('define a prioridade do paciente', function () {
    actingAsPatientStaff($this)
        ->putJson(route('panel.patients.priority.update', $this->patientInA), ['priority_rating' => 4])
        ->assertOk()
        ->assertJsonPath('priority_rating', 4);

    expect($this->patientInA->fresh()->priority_rating)->toBe(4);
});

it('0 limpa a prioridade (vira null, não fica 0 salvo)', function () {
    $this->patientInA->update(['priority_rating' => 3]);

    actingAsPatientStaff($this)
        ->putJson(route('panel.patients.priority.update', $this->patientInA), ['priority_rating' => 0])
        ->assertOk()
        ->assertJsonPath('priority_rating', null);

    expect($this->patientInA->fresh()->priority_rating)->toBeNull();
});

it('valor acima de 5 é rejeitado (422)', function () {
    actingAsPatientStaff($this)
        ->putJson(route('panel.patients.priority.update', $this->patientInA), ['priority_rating' => 6])
        ->assertStatus(422);
});

it('[SEGURANÇA] staff de OUTRA entity não define prioridade de paciente que não é dela (404)', function () {
    $staffB      = User::factory()->create();
    $entityUserB = createEntityUser($this->entityB, $staffB, 'admin');

    $this->actingAs($staffB)
        ->withSession(panelSession($entityUserB))
        ->putJson(route('panel.patients.priority.update', $this->patientInA), ['priority_rating' => 5])
        ->assertNotFound();

    expect($this->patientInA->fresh()->priority_rating)->toBeNull();
});
