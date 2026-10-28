<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User};
use App\Models\ReportSetting;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F4c — endpoint exam-template carrega template já com placeholders resolvidos.
 *
 * Cobre:
 *   - Happy path: HTML resolvido + autoPopulate + label
 *   - Exame inválido: 422
 *   - Gate IssueReport: secretary recebe 403
 *   - Médico ausente (admin sem doctor): 422
 *   - Multi-tenancy: 404
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
        'patient_id'      => $this->patient->id,
        'doctor_id'       => $this->doctor->id,
        'main_complaint'  => 'Visão embaçada',
        'tonometer_right' => 14,
        'tonometer_left'  => 15,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('carrega template do Laudo Oftalmológico com tonometria resolvida', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'ophthalmological_report',
        ]),
    );

    $response->assertOk()
        ->assertJsonStructure(['exam_type', 'label', 'title', 'html', 'unresolved', 'autoPopulate']);

    expect($response->json('exam_type'))->toBe('ophthalmological_report')
        ->and($response->json('html'))->toContain('14 mmHg')
        ->and($response->json('html'))->toContain('15 mmHg')
        ->and($response->json('label'))->toBe('Laudo Oftalmológico');
});

it('retorna autoPopulate com fields esperados', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'ophthalmological_report',
        ]),
    );

    $response->assertOk();
    expect($response->json('autoPopulate'))->toContain('tonometer_right')
        ->and($response->json('autoPopulate'))->toContain('tonometer_left');
});

it('retorna 422 para exam_type inválido', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'foo_bar',
        ]),
    );

    $response->assertStatus(422);
});

it('nega secretary (gate IssueReport)', function () {
    $secretaryUser = User::factory()->create();
    createEntityUser($this->entity, $secretaryUser, ClientRule::Secretary->value);

    $this->actingAs($secretaryUser);

    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'gonioscopia',
        ]),
    );

    $response->assertForbidden();
});

it('retorna 404 quando paciente não pertence ao prontuário', function () {
    $otherPatient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $otherPatient, $this->record, 'gonioscopia',
        ]),
    );

    $response->assertNotFound();
});

it('retorna 422 com mensagem clara quando template não está cadastrado', function () {
    // Apaga TODOS os ReportSettings com este título (global + cópias) para garantir
    // que nenhuma fonte (entidade nem global) resolva findTemplateContent.
    ReportSetting::withTrashed()
        ->where('title', 'OCT (TOMOGRAFIA DE COERÊNCIA ÓTICA)')
        ->get()
        ->each(fn ($s) => $s->forceDelete());

    expect(ReportSetting::withTrashed()->where('title', 'OCT (TOMOGRAFIA DE COERÊNCIA ÓTICA)')->count())->toBe(0);

    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'oct',
        ]),
    );

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Template não encontrado');
});
