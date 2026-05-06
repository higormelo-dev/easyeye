<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DataAccessPurpose};
use App\Models\{Covenant, DataAccessLog, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F10 — trilha de acesso (CFM Res. 2.227/2018 + LGPD Art. 37).
 *
 * Cobre quick-actions emitindo documentos: cada emissão grava entrada em
 * `data_access_logs` com purpose=Report. Auto-populate de laudo (examTemplate)
 * grava purpose=PatientCare por ler conteúdo clínico do prontuário.
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
        'main_complaint'  => 'Conjuntivite',
        'tonometer_right' => 14,
        'tonometer_left'  => 15,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

function postIssue(array $patientRecord, string $action, array $payload = [])
{
    [$patient, $record] = $patientRecord;

    return test()->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $patient, $record, $action,
        ]),
        $payload,
    );
}

it('grava data_access_log na emissão de atestado de comparecimento', function () {
    expect(DataAccessLog::count())->toBe(0);

    postIssue([$this->patient, $this->record], 'attendance-certificate', [])
        ->assertCreated();

    $logs = DataAccessLog::all();
    expect($logs)->toHaveCount(1);

    $log = $logs->first();
    expect($log->purpose)->toBe(DataAccessPurpose::Report)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->entity_id)->toBe($this->entity->id)
        ->and($log->patient_id)->toBe($this->patient->id)
        ->and($log->resource_type)->toBe(MedicalRecordDocumentation::class)
        ->and($log->ip_address)->not->toBeNull();
});

it('grava log na emissão de atestado médico', function () {
    postIssue([$this->patient, $this->record], 'medical-certificate', ['days' => 3])
        ->assertCreated();

    expect(DataAccessLog::where('purpose', DataAccessPurpose::Report->value)->count())->toBe(1);
});

it('grava log na emissão de receituário de catarata', function () {
    postIssue([$this->patient, $this->record], 'cataract-prescription', [
        'eye'      => 'right',
        'template' => 'pre_operatorio',
    ])->assertCreated();

    $log = DataAccessLog::first();
    expect($log->purpose)->toBe(DataAccessPurpose::Report)
        ->and($log->patient_id)->toBe($this->patient->id);
});

it('grava log na emissão de laudo de exame (gonioscopia)', function () {
    postIssue([$this->patient, $this->record], 'exam-report', [
        'exam_type' => 'gonioscopia',
    ])->assertCreated();

    expect(DataAccessLog::count())->toBe(1);
});

it('NÃO grava log quando emissão falha por validação', function () {
    postIssue([$this->patient, $this->record], 'medical-certificate', ['days' => 0])
        ->assertStatus(422);

    expect(DataAccessLog::count())->toBe(0);
});

it('NÃO grava log quando paciente não pertence ao prontuário (404)', function () {
    $otherPatient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    postIssue([$otherPatient, $this->record], 'attendance-certificate', [])
        ->assertNotFound();

    expect(DataAccessLog::count())->toBe(0);
});

it('grava log com purpose=PatientCare ao acessar exam-template (auto-populate)', function () {
    $r = $this->getJson(route('panel.patients.medicalrecords.exam-template', [
        $this->patient, $this->record, 'gonioscopia',
    ]));
    $r->assertOk();

    $log = DataAccessLog::first();
    expect($log)->not->toBeNull()
        ->and($log->purpose)->toBe(DataAccessPurpose::PatientCare)
        ->and($log->patient_id)->toBe($this->patient->id)
        ->and($log->resource_type)->toBe(MedicalRecord::class);
});

it('grava log a cada emissão (múltiplos)', function () {
    postIssue([$this->patient, $this->record], 'attendance-certificate', [])->assertCreated();
    postIssue([$this->patient, $this->record], 'medical-certificate', ['days' => 7])->assertCreated();
    postIssue([$this->patient, $this->record], 'pterygium-prescription', [])->assertCreated();

    expect(DataAccessLog::where('purpose', DataAccessPurpose::Report->value)->count())->toBe(3);
});

it('preserva user_agent e ip_address no log', function () {
    $this->withHeaders(['User-Agent' => 'TestBrowser/1.0'])
        ->postJson(
            route('panel.patients.medicalrecords.quick-actions.issue', [
                $this->patient, $this->record, 'attendance-certificate',
            ]),
            [],
        )->assertCreated();

    $log = DataAccessLog::first();
    expect($log->user_agent)->toBe('TestBrowser/1.0')
        ->and($log->ip_address)->not->toBeNull();
});

it('grava log da emissão de medication-prescription via builder', function () {
    postIssue([$this->patient, $this->record], 'medication-prescription', [
        'content' => '- Dipirona 500mg 3x ao dia',
    ])->assertCreated();

    $log = DataAccessLog::first();
    expect($log->purpose)->toBe(DataAccessPurpose::Report);
});

it('grava log da emissão de procedure-request', function () {
    postIssue([$this->patient, $this->record], 'procedure-request', [
        'content' => '- Mapeamento de retina (Rotina)',
    ])->assertCreated();

    expect(DataAccessLog::count())->toBe(1);
});
