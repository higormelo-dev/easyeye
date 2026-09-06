<?php

declare(strict_types=1);

/**
 * Autoatendimento LGPD (Fase 4 do Portal do Paciente) — Art. 18, II/V.
 * O titular baixa os próprios dados de uma clínica sem depender do staff.
 */

use App\Enums\{ClientRule, DocumentationType, LgpdRequestStatus, LgpdRequestType};
use App\Models\{Covenant, DataAccessLog, Doctor, Entity, LgpdRequest, MedicalRecord,
    MedicalRecordDocumentation, Patient, PatientAccount, People, User};

function makeLgpdExportFixture(): array
{
    $entity     = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica Teste']);
    $staff      = User::factory()->create();
    $entityUser = createEntityUser($entity, $staff, ClientRule::Doctor->value);

    $person  = People::factory()->create(['full_name' => 'Paciente Teste', 'national_registry' => '12345678900']);
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => $person->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    $doctorPerson = People::factory()->create();
    $doctor       = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => $doctorPerson->id,
        'record'         => '77777',
        'color'          => '#ABCDEF',
        'partner'        => false,
        'active'         => true,
    ]);

    $record = MedicalRecord::create([
        'patient_id'     => $patient->id,
        'doctor_id'      => $doctor->id,
        'main_complaint' => 'Baixa de acuidade visual progressiva',
    ]);

    MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $patient->id,
        'doctor_id'         => $doctor->id,
        'type'              => DocumentationType::Prescription,
        'title'             => 'Receituário de colírio',
        'content'           => '<p>Colírio X, 1 gota 3x ao dia.</p>',
    ]);

    $account = PatientAccount::factory()->create(['person_id' => $person->id]);

    return compact('entity', 'entityUser', 'patient', 'doctor', 'record', 'account', 'person');
}

test('titular baixa os proprios dados: 200, JSON com o conteudo clinico real, e cria LgpdRequest concluido automaticamente', function () {
    $f = makeLgpdExportFixture();
    loginAsPatient($f['account']);

    $response = $this->get(route('patient-portal.clinics.export', $f['patient']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');

    $data = json_decode($response->getContent(), true);

    // Entity, assim como People, tem uppercase automático em name — compara
    // com o valor real persistido, não com o literal passado à factory.
    expect($data['clinic']['name'])->toBe($f['entity']->fresh()->name);
    expect($data['personal_data']['full_name'])->toBe($f['person']->fresh()->full_name);
    expect($data['medical_records'])->toHaveCount(1);
    expect($data['medical_records'][0]['main_complaint'])->toBe('Baixa de acuidade visual progressiva');
    expect($data['medical_records'][0]['documentations'])->toHaveCount(1);
    expect($data['medical_records'][0]['documentations'][0]['title'])->toBe('Receituário de colírio');

    $lgpdRequest = LgpdRequest::query()->where('patient_id', $f['patient']->id)->first();
    expect($lgpdRequest)->not->toBeNull();
    expect($lgpdRequest->entity_id)->toBe($f['entity']->id);
    expect($lgpdRequest->request_type)->toBe(LgpdRequestType::Access);
    expect($lgpdRequest->status)->toBe(LgpdRequestStatus::Completed);
    expect($lgpdRequest->responded_by)->toBeNull();
    expect($lgpdRequest->responded_at)->not->toBeNull();
});

test('registra data_access_log com patient_account_id (nao user_id) e purpose lgpd_request', function () {
    $f = makeLgpdExportFixture();
    loginAsPatient($f['account']);

    $this->get(route('patient-portal.clinics.export', $f['patient']));

    $log = DataAccessLog::query()->where('patient_id', $f['patient']->id)->latest('accessed_at')->first();

    expect($log)->not->toBeNull();
    expect($log->purpose->value)->toBe('lgpd_request');
    expect($log->patient_account_id)->toBe($f['account']->id);
    expect($log->user_id)->toBeNull();
});

test('paciente de outro titular (person_id diferente) nunca exporta dados alheios, 404 nunca 403', function () {
    $f = makeLgpdExportFixture();

    $outroPerson  = People::factory()->create();
    $outroAccount = PatientAccount::factory()->create(['person_id' => $outroPerson->id]);
    loginAsPatient($outroAccount);

    $this->get(route('patient-portal.clinics.export', $f['patient']))->assertNotFound();

    expect(LgpdRequest::query()->count())->toBe(0);
});

test('sem sessao patient nega acesso, nunca vaza dados', function () {
    $f = makeLgpdExportFixture();

    $this->getJson(route('patient-portal.clinics.export', $f['patient']))->assertUnauthorized();

    expect(LgpdRequest::query()->count())->toBe(0);
});
