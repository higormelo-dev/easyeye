<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordEvolution, Patient, People, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Evoluções clínicas em texto livre (append-only).
 *
 * Escrita: exclusiva de médico (Gate IssueReport). Leitura: grupo do
 * prontuário (admin/doctor/secretary). Cada evolução carimba doctor + data.
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

    $this->record = MedicalRecord::create([
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta de rotina',
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('médico registra evolução com carimbo de doctor e data/hora', function () {
    $response = $this->postJson(
        route('panel.patients.medicalrecords.evolutions.store', [$this->patient, $this->record]),
        ['content' => 'Paciente evolui bem, PIO controlada.'],
    );

    $response->assertCreated()
        ->assertJsonPath('content', 'Paciente evolui bem, PIO controlada.')
        ->assertJsonPath('doctor_name', $this->doctor->person->full_name)
        ->assertJsonStructure(['id', 'content', 'doctor_name', 'created_at']);

    $evolution = MedicalRecordEvolution::withoutGlobalScopes()->first();
    expect($evolution)->not->toBeNull()
        ->and((string) $evolution->entity_id)->toBe((string) $this->entity->id)
        ->and((string) $evolution->patient_id)->toBe((string) $this->patient->id)
        ->and((string) $evolution->medical_record_id)->toBe((string) $this->record->id)
        ->and((string) $evolution->doctor_id)->toBe((string) $this->doctor->id);
});

it('content é obrigatório — 422 sem texto', function () {
    $this->postJson(
        route('panel.patients.medicalrecords.evolutions.store', [$this->patient, $this->record]),
        ['content' => ''],
    )->assertUnprocessable();
});

it('listagem cronológica por paciente: mais recente primeiro, atravessa prontuários', function () {
    $older = MedicalRecordEvolution::create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'medical_record_id' => $this->record->id,
        'doctor_id'         => $this->doctor->id,
        'content'           => 'Primeira evolução',
    ]);
    $older->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    $secondRecord = MedicalRecord::create([
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Retorno',
    ]);
    MedicalRecordEvolution::create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'medical_record_id' => $secondRecord->id,
        'doctor_id'         => $this->doctor->id,
        'content'           => 'Segunda evolução (outro prontuário)',
    ]);

    $response = $this->getJson(route('panel.patients.evolutions.index', $this->patient));

    $response->assertOk();
    $contents = collect($response->json('data'))->pluck('content')->all();
    expect($contents)->toBe(['Segunda evolução (outro prontuário)', 'Primeira evolução']);
});

it('secretária NÃO grava evolução (403), mas lê o histórico (200)', function () {
    $secretaryUser = User::factory()->create();
    createEntityUser($this->entity, $secretaryUser, ClientRule::Secretary->value);

    $this->actingAs($secretaryUser);
    session(['selected_entity_id' => $this->entity->id]);

    $this->postJson(
        route('panel.patients.medicalrecords.evolutions.store', [$this->patient, $this->record]),
        ['content' => 'tentativa'],
    )->assertForbidden();

    $this->getJson(route('panel.patients.evolutions.index', $this->patient))->assertOk();
});

it('isolamento multi-tenant: paciente de outra entity retorna 404', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true]);
    $otherPerson  = People::factory()->create();
    $otherPatient = Patient::create([
        'entity_id'   => $otherEntity->id,
        'person_id'   => $otherPerson->id,
        'covenant_id' => Covenant::factory()->create()->id,
        // Explícito: o gerador de code conta POR entity, mas a unique é global —
        // segunda entity colidiria em PAC-0000000001 dentro do mesmo teste.
        'code'   => 'PAC-9999999999',
        'active' => true,
    ]);

    $this->getJson(route('panel.patients.evolutions.index', $otherPatient))
        ->assertNotFound();
});
