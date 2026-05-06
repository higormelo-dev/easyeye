<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F8 — Receituário de catarata via quick-action `cataract-prescription`.
 *
 * Cobre validação canônica (eye/template enum), mapeamento PT-BR para
 * placeholders, escopo multi-tenant e gate IssueReport.
 */
beforeEach(function () {
    $this->seed([
        ReportSettingSeeder::class,
        ReportSettingContentSeeder::class,
        ReportSettingVariableSeeder::class,
    ]);

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
        'main_complaint' => 'Catarata madura',
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

function postCataract(array $patientRecord, array $payload)
{
    [$patient, $record] = $patientRecord;

    return test()->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $patient, $record, 'cataract-prescription',
        ]),
        $payload,
    );
}

it('emite receituário pré-operatório com olho direito (happy path)', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'      => 'right',
        'template' => 'pre_operatorio',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['id', 'type', 'type_label', 'title', 'created_at', 'pdf_url']);

    $doc = MedicalRecordDocumentation::where('medical_record_id', $this->record->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->title)->toBe('Receituário de Catarata')
        ->and($doc->content)->toContain('OLHO DIREITO')
        ->and($doc->content)->not->toContain('{{OLHO_OPERADO}}');
});

it('emite receituário com instruções cirúrgicas e propaga data/hora', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'          => 'left',
        'template'     => 'instrucoes_cirurgicas',
        'date_surgery' => '15/05/2026',
        'hour_surgery' => '08:30',
    ]);

    $response->assertCreated();

    $doc = MedicalRecordDocumentation::first();
    expect($doc->content)->toContain('OLHO ESQUERDO')
        ->and($doc->content)->toContain('15/05/2026')
        ->and($doc->content)->toContain('08:30');
});

it('aceita "ambos os olhos" mapeado a partir de payload canônico both', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'      => 'both',
        'template' => 'pos_operatorio',
    ]);

    $response->assertCreated();
    expect(MedicalRecordDocumentation::first()->content)->toContain('AMBOS OS OLHOS');
});

it('aceita identificadores legacy 1/2/3 no campo template', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'      => 'right',
        'template' => '2',
    ]);

    $response->assertCreated();
    // Slug pos_operatorio gerou doc — basta checar que persistiu sem 422.
    expect(MedicalRecordDocumentation::count())->toBe(1);
});

it('rejeita eye fora do enum [right, left, both]', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'      => 'OD',
        'template' => 'pre_operatorio',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['eye']);
});

it('rejeita eye ausente', function () {
    $response = postCataract([$this->patient, $this->record], [
        'template' => 'pre_operatorio',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['eye']);
});

it('rejeita template fora do enum aceito', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'      => 'right',
        'template' => 'invalido',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['template']);
});

it('rejeita date_surgery em formato inválido', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'          => 'right',
        'template'     => 'instrucoes_cirurgicas',
        'date_surgery' => '2026-05-15',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['date_surgery']);
});

it('rejeita hour_surgery em formato inválido', function () {
    $response = postCataract([$this->patient, $this->record], [
        'eye'          => 'right',
        'template'     => 'instrucoes_cirurgicas',
        'hour_surgery' => '8h30',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['hour_surgery']);
});

it('nega secretary (gate IssueReport)', function () {
    $secretary = User::factory()->create();
    createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

    $this->actingAs($secretary);

    $response = postCataract([$this->patient, $this->record], [
        'eye'      => 'right',
        'template' => 'pre_operatorio',
    ]);

    $response->assertForbidden();
});

it('retorna 404 quando paciente não pertence ao prontuário', function () {
    $otherPatient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    $response = postCataract([$otherPatient, $this->record], [
        'eye'      => 'right',
        'template' => 'pre_operatorio',
    ]);

    $response->assertNotFound();
});
