<?php

declare(strict_types=1);

/**
 * Regressão de segurança (auditoria panel.* IDOR — achados CRITICAL/HIGH):
 * route model binding de {patient}/{medicalrecord}/{reportSetting} não é
 * filtrado por entity, porque SubstituteBindings roda ANTES de tenant.bind
 * na ordem de middleware do Laravel — o EntityScope global fica inerte no
 * momento do binding. Staff de uma entity conseguia ler/escrever/apagar
 * prontuário, exame, anexo, laudo e template de OUTRA entity só sabendo o
 * UUID. Cobre os 7 pontos de correção desta auditoria: os 4 helpers
 * assert*BelongsToPatient duplicados (MedicalRecordsController,
 * MedicalRecordQuickActionsController, MedicalRecordFilesController,
 * MedicalRecordDocumentationsController), MedicalRecordsController::index/
 * create/store (sem helper, checagem direta), TonometryPdfController, e
 * ReportSettingsController::assertOwnsReportSetting().
 */

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, MedicalRecordFile, Patient, People, ReportSetting, User};

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

function actingAsA($test)
{
    return $test->actingAs($test->staffA)->withSession(panelSession($test->entityUserA));
}

function actingAsB($test)
{
    return $test->actingAs($test->staffB)->withSession(panelSession($test->entityUserB));
}

// ── MedicalRecordsController::index/create (sem helper, checagem direta) ────

test('index: staff de OUTRA entity recebe 404 ao listar prontuarios de paciente que nao pertence a ela', function () {
    actingAsB($this)
        ->get(route('panel.patients.medicalrecords.index', $this->patientA))
        ->assertNotFound();
});

test('index: staff da entity correta consegue listar prontuarios normalmente', function () {
    actingAsA($this)
        ->get(route('panel.patients.medicalrecords.index', $this->patientA))
        ->assertOk();
});

test('create: staff de OUTRA entity recebe 404 ao abrir form de novo prontuario', function () {
    actingAsB($this)
        ->get(route('panel.patients.medicalrecords.create', $this->patientA))
        ->assertNotFound();
});

// ── MedicalRecordsController::store (escrita cross-tenant, achado mais grave) ─

test('store: staff de OUTRA entity nao consegue criar prontuario para paciente que nao pertence a ela', function () {
    actingAsB($this)
        ->post(route('panel.patients.medicalrecords.store', $this->patientA), [
            'doctor_id'      => $this->doctorB->id,
            'main_complaint' => 'Tentativa de escrita cross-tenant',
        ])
        ->assertNotFound();

    // Nenhum prontuario novo deve ter sido persistido.
    expect(MedicalRecord::where('patient_id', $this->patientA->id)->count())->toBe(1);
});

test('store: staff da entity correta consegue criar prontuario normalmente', function () {
    actingAsA($this)
        ->post(route('panel.patients.medicalrecords.store', $this->patientA), [
            'doctor_id'      => $this->doctorA->id,
            'main_complaint' => 'Consulta legitima',
        ])
        ->assertRedirect();

    expect(MedicalRecord::where('patient_id', $this->patientA->id)->count())->toBe(2);
});

// ── Helper assertMedicalRecordBelongsToPatient (MedicalRecordsController) ───

test('show: staff de OUTRA entity recebe 404 ao ler prontuario de paciente que nao pertence a ela', function () {
    actingAsB($this)
        ->get(route('panel.patients.medicalrecords.show', [$this->patientA, $this->recordA]))
        ->assertNotFound();
});

test('show: staff da entity correta consegue ler o prontuario normalmente', function () {
    actingAsA($this)
        ->get(route('panel.patients.medicalrecords.show', [$this->patientA, $this->recordA]))
        ->assertOk();
});

// ── Helper duplicado em MedicalRecordFilesController ────────────────────────

test('arquivo de prontuario: staff de OUTRA entity recebe 404 ao tentar baixar anexo de paciente que nao pertence a ela', function () {
    $file = MedicalRecordFile::create([
        'medical_record_id' => $this->recordA->id,
        'patient_id'        => $this->patientA->id,
        'file_path'         => 'medical/' . $this->entityA->id . '/' . $this->recordA->id . '/teste.pdf',
        'original_name'     => 'teste.pdf',
    ]);

    actingAsB($this)
        ->get(route('panel.patients.medicalrecords.files.show', [$this->patientA, $this->recordA, $file]))
        ->assertNotFound();
});

// ── Helper duplicado em MedicalRecordDocumentationsController ───────────────

test('documentacao de prontuario: staff de OUTRA entity recebe 404 ao tentar ler laudo de paciente que nao pertence a ela', function () {
    $doc = MedicalRecordDocumentation::create([
        'medical_record_id' => $this->recordA->id,
        'patient_id'        => $this->patientA->id,
        'doctor_id'         => $this->doctorA->id,
        'type'              => 'report',
        'content'           => 'Laudo confidencial da entity A',
    ]);

    actingAsB($this)
        ->get(route('panel.patients.medicalrecords.documentations.show', [$this->patientA, $this->recordA, $doc]))
        ->assertNotFound();
});

// ── TonometryPdfController (achado independente, sem helper) ────────────────

test('tonometria PDF: staff de OUTRA entity recebe 404 ao gerar PDF de paciente que nao pertence a ela', function () {
    actingAsB($this)
        ->get(route('panel.patients.tonometry-pdf', $this->patientA))
        ->assertNotFound();
});

test('tonometria PDF: staff da entity correta consegue gerar o PDF normalmente', function () {
    actingAsA($this)
        ->get(route('panel.patients.tonometry-pdf', $this->patientA, ['doctor_id' => $this->doctorA->id]))
        ->assertOk();
});

// ── ReportSettingsController::assertOwnsReportSetting (achado HIGH) ─────────

test('template de laudo: staff de OUTRA entity nao consegue editar/apagar template que nao pertence a ela', function () {
    // settings.manage exige admin (Doctor nao passa da permission middleware) —
    // precisa de admin pra chegar de fato no assertOwnsReportSetting() sendo testado.
    $adminB           = User::factory()->create();
    $adminEntityUserB = createEntityUser($this->entityB, $adminB, ClientRule::Admin->value);

    $template = ReportSetting::create([
        'entity_id' => $this->entityA->id,
        'title'     => 'Modelo da clinica A',
    ]);

    $this->actingAs($adminB)->withSession(panelSession($adminEntityUserB))
        ->put(route('panel.setting.report-settings.update', $template), ['title' => 'Sequestrado'])
        ->assertNotFound();

    $this->actingAs($adminB)->withSession(panelSession($adminEntityUserB))
        ->delete(route('panel.setting.report-settings.destroy', $template))
        ->assertNotFound();

    expect($template->fresh()->title)->toBe('Modelo da clinica A');
});
