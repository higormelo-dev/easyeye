<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DataAccessPurpose};
use App\Models\{Covenant, DataAccessLog, Doctor, Entity, MedicalRecord, Patient, People, User};
use App\Services\DataAccessLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Registro de acesso (CFM 2.227/2018 + LGPD art. 37) vinculado ao paciente.
 * Quando o recurso acessado é o próprio Patient (lista de prontuários, laudo
 * de imagens), o log saía sem patient_id e ficava fora de
 * Patient::accessLogs() — o filtro por paciente e o resumo de acessos da
 * exportação LGPD não o enxergavam.
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $this->doctor     = Doctor::create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('lista de prontuários registra o acesso vinculado ao paciente', function () {
    $this->withSession(panelSession($this->entityUser))
        ->getJson(route('panel.patients.medicalrecords.ajaxlist', $this->patient))
        ->assertOk();

    $log = $this->patient->accessLogs()->sole();

    expect($log->resource_type)->toBe(Patient::class)
        ->and($log->resource_id)->toBe($this->patient->id)
        ->and($log->purpose)->toBe(DataAccessPurpose::PatientCare)
        ->and($log->entity_id)->toBe($this->entity->id);
});

it('acesso cujo recurso é o próprio paciente fica vinculado a ele', function () {
    app(DataAccessLogService::class)->log($this->patient, DataAccessPurpose::PatientCare);

    expect(DataAccessLog::sole()->patient_id)->toBe($this->patient->id);
});

it('recurso com patient_id segue usando o atributo; patientId explícito tem prioridade', function () {
    $record = MedicalRecord::create([
        'entity_id'      => $this->entity->id,
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta',
    ]);
    $other = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    app(DataAccessLogService::class)->log($record, DataAccessPurpose::PatientCare);
    app(DataAccessLogService::class)->log($this->patient, DataAccessPurpose::PatientCare, patientId: (string) $other->id);

    expect(DataAccessLog::where('resource_type', MedicalRecord::class)->sole()->patient_id)->toBe($this->patient->id)
        ->and(DataAccessLog::where('resource_type', Patient::class)->sole()->patient_id)->toBe($other->id);
});
