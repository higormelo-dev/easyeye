<?php

declare(strict_types=1);

/**
 * Staff-side do compartilhamento (PatientDocumentSharesController) — mesmo
 * padrão de isolamento cross-tenant da auditoria panel.* IDOR (38 achados
 * corrigidos nesta sessão). Fase 2 do plano "Portal do Paciente".
 */

use App\Enums\{ClientRule, DocumentationType, ExamSource};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientDocumentShare, PatientExam, People, User};

beforeEach(function () {
    $this->entityA = Entity::factory()->create(['is_client' => true]);
    $this->entityB = Entity::factory()->create(['is_client' => true]);

    $this->staffA      = User::factory()->create();
    $this->staffB      = User::factory()->create();
    $this->entityUserA = createEntityUser($this->entityA, $this->staffA, ClientRule::Doctor->value);
    $this->entityUserB = createEntityUser($this->entityB, $this->staffB, ClientRule::Doctor->value);

    $personA        = People::factory()->create();
    $this->patientA = Patient::create([
        'entity_id'   => $this->entityA->id,
        'person_id'   => $personA->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    $doctorPersonA = People::factory()->create();
    $this->doctorA = Doctor::create([
        'entity_user_id' => $this->entityUserA->id,
        'person_id'      => $doctorPersonA->id,
        'record'         => '55555',
        'color'          => '#123456',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->examA = PatientExam::factory()->create([
        'patient_id' => $this->patientA->id,
        'doctor_id'  => $this->doctorA->id,
        'active'     => true,
        'source'     => ExamSource::ExternalImport,
    ]);
});

function actAsEntityA($test)
{
    return $test->actingAs($test->staffA)->withSession(panelSession($test->entityUserA));
}

function actAsEntityB($test)
{
    return $test->actingAs($test->staffB)->withSession(panelSession($test->entityUserB));
}

function actAsSecretaryA($test)
{
    $secretaryUser = User::factory()->create();
    $entityUser    = createEntityUser($test->entityA, $secretaryUser, ClientRule::Secretary->value);

    return $test->actingAs($secretaryUser)->withSession(panelSession($entityUser));
}

function signedLaudoFixture($test): MedicalRecordDocumentation
{
    $record = MedicalRecord::create([
        'patient_id'     => $test->patientA->id,
        'doctor_id'      => $test->doctorA->id,
        'main_complaint' => 'Consulta',
    ]);
    $record->sign($test->entityUserA);

    return MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $test->patientA->id,
        'doctor_id'         => $test->doctorA->id,
        'type'              => DocumentationType::Prescription,
        'title'             => 'Receituário',
        'content'           => '<p>x</p>',
    ]);
}

test('staff concede grant e ele fica ativo', function () {
    actAsEntityA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'exame',
            'shareable_id'   => $this->examA->id,
            'patient_id'     => $this->patientA->id,
        ])
        ->assertCreated()
        ->assertJson(['shared' => true]);

    expect(PatientDocumentShare::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('grant duplicado e idempotente — nao cria segunda linha', function () {
    actAsEntityA($this)->postJson(route('panel.document-shares.store'), [
        'shareable_type' => 'exame',
        'shareable_id'   => $this->examA->id,
        'patient_id'     => $this->patientA->id,
    ])->assertCreated();

    actAsEntityA($this)->postJson(route('panel.document-shares.store'), [
        'shareable_type' => 'exame',
        'shareable_id'   => $this->examA->id,
        'patient_id'     => $this->patientA->id,
    ])->assertCreated();

    expect(PatientDocumentShare::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('staff de OUTRA entidade nao concede grant de paciente que nao e dele (404)', function () {
    actAsEntityB($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'exame',
            'shareable_id'   => $this->examA->id,
            'patient_id'     => $this->patientA->id,
        ])
        ->assertNotFound();

    expect(PatientDocumentShare::query()->count())->toBe(0);
});

test('staff de OUTRA entidade nao revoga grant de paciente que nao e dele (404)', function () {
    $share = actAsEntityA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'exame',
            'shareable_id'   => $this->examA->id,
            'patient_id'     => $this->patientA->id,
        ])->json();

    actAsEntityB($this)
        ->deleteJson(route('panel.document-shares.destroy', $share['id']))
        ->assertNotFound();

    expect(PatientDocumentShare::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('laudo de prontuario nao assinado nao pode ser compartilhado (422)', function () {
    $record = MedicalRecord::create([
        'patient_id'     => $this->patientA->id,
        'doctor_id'      => $this->doctorA->id,
        'main_complaint' => 'Consulta',
    ]);

    $doc = MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $this->patientA->id,
        'doctor_id'         => $this->doctorA->id,
        'type'              => DocumentationType::Prescription,
        'title'             => 'Receituário',
        'content'           => '<p>x</p>',
    ]);

    actAsEntityA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'laudo',
            'shareable_id'   => $doc->id,
            'patient_id'     => $this->patientA->id,
        ])
        ->assertStatus(422);

    expect(PatientDocumentShare::query()->count())->toBe(0);
});

test('doctor compartilha laudo JA assinado com sucesso (201)', function () {
    $doc = signedLaudoFixture($this);

    actAsEntityA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'laudo',
            'shareable_id'   => $doc->id,
            'patient_id'     => $this->patientA->id,
        ])
        ->assertCreated()
        ->assertJson(['shared' => true]);

    expect(PatientDocumentShare::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('secretaria NAO pode compartilhar laudo com o paciente, mesmo ja assinado (403)', function () {
    // Decisão de produto: secretária não decide exposição de conteúdo
    // clínico assinado ao paciente — só admin/doctor (EntityGate::ShareLaudoWithPatient).
    $doc = signedLaudoFixture($this);

    actAsSecretaryA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'laudo',
            'shareable_id'   => $doc->id,
            'patient_id'     => $this->patientA->id,
        ])
        ->assertForbidden();

    expect(PatientDocumentShare::query()->count())->toBe(0);
});

test('secretaria NAO pode revogar compartilhamento de laudo (403)', function () {
    $doc = signedLaudoFixture($this);

    $share = actAsEntityA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'laudo',
            'shareable_id'   => $doc->id,
            'patient_id'     => $this->patientA->id,
        ])->json();

    actAsSecretaryA($this)
        ->deleteJson(route('panel.document-shares.destroy', $share['id']))
        ->assertForbidden();

    expect(PatientDocumentShare::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('secretaria CONTINUA podendo compartilhar e revogar EXAME (restricao e so pra laudo)', function () {
    actAsSecretaryA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'exame',
            'shareable_id'   => $this->examA->id,
            'patient_id'     => $this->patientA->id,
        ])
        ->assertCreated()
        ->assertJson(['shared' => true]);

    $share = PatientDocumentShare::query()->whereNull('revoked_at')->first();

    actAsSecretaryA($this)
        ->deleteJson(route('panel.document-shares.destroy', $share->id))
        ->assertOk()
        ->assertJson(['shared' => false]);
});

test('revogar torna o grant inativo', function () {
    $share = actAsEntityA($this)
        ->postJson(route('panel.document-shares.store'), [
            'shareable_type' => 'exame',
            'shareable_id'   => $this->examA->id,
            'patient_id'     => $this->patientA->id,
        ])->json();

    actAsEntityA($this)
        ->deleteJson(route('panel.document-shares.destroy', $share['id']))
        ->assertOk()
        ->assertJson(['shared' => false]);

    expect(PatientDocumentShare::query()->whereNull('revoked_at')->count())->toBe(0);
});
