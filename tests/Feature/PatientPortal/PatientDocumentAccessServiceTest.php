<?php

declare(strict_types=1);

/**
 * PatientDocumentAccessService — ÚNICO ponto de leitura pros 3 tipos
 * compartilháveis (laudo/exame/anexo). Fase 2 do plano "Portal do Paciente".
 */

use App\Enums\{ClientRule, DocumentationType, ExamSource};
use App\Models\{Covenant, DataAccessLog, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, MedicalRecordFile,
    Patient, PatientAccount, PatientDocumentShare, PatientExam, People, User};
use Illuminate\Support\Facades\Storage;

function makeSharePortalFixture(): array
{
    $entity     = Entity::factory()->create(['is_client' => true]);
    $staff      = User::factory()->create();
    $entityUser = createEntityUser($entity, $staff, ClientRule::Doctor->value);

    $person  = People::factory()->create();
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
        'record'         => '99999',
        'color'          => '#FFFFFF',
        'partner'        => false,
        'active'         => true,
    ]);

    $record = MedicalRecord::create([
        'patient_id'     => $patient->id,
        'doctor_id'      => $doctor->id,
        'main_complaint' => 'Consulta de rotina',
    ]);

    $account = PatientAccount::factory()->create(['person_id' => $person->id]);

    return compact('entity', 'entityUser', 'patient', 'doctor', 'record', 'account');
}

test('exame com grant ativo é lido pelo dono; revogado vira 404 imediato', function () {
    $f = makeSharePortalFixture();

    $exam = PatientExam::factory()->create([
        'patient_id' => $f['patient']->id,
        'doctor_id'  => $f['doctor']->id,
        'active'     => true,
        'source'     => ExamSource::ExternalImport,
    ]);

    $share = PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => PatientExam::class,
        'shareable_id'   => $exam->id,
        'granted_by'     => $f['entityUser']->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    $this->get(route('patient-portal.documents.view', ['exame', $exam->id]))->assertOk();

    $share->update(['revoked_at' => now()]);

    $this->get(route('patient-portal.documents.view', ['exame', $exam->id]))->assertNotFound();
});

test('[GAP] exame de vídeo compartilhado: isImage vem false e isVideo vem true (nunca renderiza <img> quebrada — achado via revisão Codex)', function () {
    $f = makeSharePortalFixture();

    $video = PatientExam::factory()->create([
        'patient_id' => $f['patient']->id,
        'doctor_id'  => $f['doctor']->id,
        'active'     => true,
        'source'     => ExamSource::BrowserCapture,
        'archive'    => 'exams/captura-123.webm',
    ]);

    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => PatientExam::class,
        'shareable_id'   => $video->id,
        'granted_by'     => $f['entityUser']->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    $this->get(route('patient-portal.documents.view', ['exame', $video->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isImage', false)->where('isVideo', true));
});

test('exame de foto compartilhado continua com isImage true e isVideo false (comportamento de sempre)', function () {
    $f = makeSharePortalFixture();

    $photo = PatientExam::factory()->create([
        'patient_id' => $f['patient']->id,
        'doctor_id'  => $f['doctor']->id,
        'active'     => true,
        'source'     => ExamSource::ExternalImport,
        'archive'    => 'exams/foto-123.jpg',
    ]);

    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => PatientExam::class,
        'shareable_id'   => $photo->id,
        'granted_by'     => $f['entityUser']->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    $this->get(route('patient-portal.documents.view', ['exame', $photo->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isImage', true)->where('isVideo', false));
});

test('paciente de outro titular (person_id diferente) nunca le, mesmo com grant ativo', function () {
    $f = makeSharePortalFixture();

    $exam = PatientExam::factory()->create([
        'patient_id' => $f['patient']->id,
        'doctor_id'  => $f['doctor']->id,
        'active'     => true,
        'source'     => ExamSource::ExternalImport,
    ]);

    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => PatientExam::class,
        'shareable_id'   => $exam->id,
        'granted_at'     => now(),
    ]);

    $outroPerson  = People::factory()->create();
    $outroAccount = PatientAccount::factory()->create(['person_id' => $outroPerson->id]);
    loginAsPatient($outroAccount);

    $this->get(route('patient-portal.documents.view', ['exame', $exam->id]))->assertNotFound();
});

test('sem grant ativo, dono nao le (404), nunca 403', function () {
    $f = makeSharePortalFixture();

    $exam = PatientExam::factory()->create([
        'patient_id' => $f['patient']->id,
        'doctor_id'  => $f['doctor']->id,
        'active'     => true,
        'source'     => ExamSource::ExternalImport,
    ]);

    loginAsPatient($f['account']);

    $this->get(route('patient-portal.documents.view', ['exame', $exam->id]))->assertNotFound();
});

test('laudo de prontuario NAO assinado nunca e legivel pelo paciente mesmo com grant forcado direto no banco', function () {
    $f = makeSharePortalFixture();

    $doc = MedicalRecordDocumentation::create([
        'medical_record_id' => $f['record']->id,
        'patient_id'        => $f['patient']->id,
        'doctor_id'         => $f['doctor']->id,
        'type'              => DocumentationType::Prescription,
        'title'             => 'Receituário',
        'content'           => '<p>conteúdo</p>',
    ]);

    // Grant forçado direto no banco (bypassando o staff-side service) —
    // defesa em profundidade: mesmo assim, prontuário não assinado bloqueia.
    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => MedicalRecordDocumentation::class,
        'shareable_id'   => $doc->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    $this->get(route('patient-portal.documents.view', ['laudo', $doc->id]))->assertNotFound();

    $f['record']->sign($f['entityUser']);

    $this->get(route('patient-portal.documents.view', ['laudo', $doc->id]))->assertOk();
});

test('anexo (MedicalRecordFile) com grant ativo é lido pelo dono', function () {
    $f = makeSharePortalFixture();

    $file = MedicalRecordFile::create([
        'medical_record_id' => $f['record']->id,
        'patient_id'        => $f['patient']->id,
        'file_path'         => 'medical-records/fake.pdf',
        'original_name'     => 'fake.pdf',
        'mime_type'         => 'application/pdf',
        'file_size'         => 10,
    ]);

    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => MedicalRecordFile::class,
        'shareable_id'   => $file->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    $this->get(route('patient-portal.documents.view', ['anexo', $file->id]))->assertOk();
});

test('anexo: show() e download() efetivamente respondem 200 com o arquivo (regressao: Illuminate\Http\StreamedResponse nao existe)', function () {
    // BUGFIX (revisão de segurança): DocumentsController::respondFile() e
    // MedicalRecordFilesController::show() declaravam o retorno como
    // Illuminate\Http\StreamedResponse — classe que NÃO EXISTE (Laravel usa
    // a do Symfony diretamente). Nenhum teste anterior batia em show()/
    // download() pra anexo (só view()), então o bug nunca foi pego. Este
    // teste cobre exatamente o caminho que tinha o retorno quebrado.
    Storage::fake();

    $f = makeSharePortalFixture();

    $path = 'medical-records/' . $f['record']->id . '/fake.pdf';
    Storage::disk()->put($path, 'conteudo fake do anexo');

    $file = MedicalRecordFile::create([
        'medical_record_id' => $f['record']->id,
        'patient_id'        => $f['patient']->id,
        'file_path'         => $path,
        'original_name'     => 'fake.pdf',
        'mime_type'         => 'application/pdf',
        'file_size'         => 23,
    ]);

    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => MedicalRecordFile::class,
        'shareable_id'   => $file->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    $this->get(route('patient-portal.documents.show', ['anexo', $file->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');

    $this->get(route('patient-portal.documents.download', ['anexo', $file->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

test('registra patient_account_id (nao user_id) em data_access_logs na leitura do proprio documento', function () {
    Storage::fake('s3');

    $f = makeSharePortalFixture();

    $exam = PatientExam::factory()->create([
        'patient_id' => $f['patient']->id,
        'doctor_id'  => $f['doctor']->id,
        'active'     => true,
        'source'     => ExamSource::ExternalImport,
        'archive'    => 'exams/fake.jpg',
    ]);

    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => PatientExam::class,
        'shareable_id'   => $exam->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    // show() (não view()) é quem efetivamente registra a leitura do conteúdo.
    $this->get(route('patient-portal.documents.show', ['exame', $exam->id]));

    $log = DataAccessLog::query()->latest('accessed_at')->first();

    expect($log)->not->toBeNull();
    expect($log->patient_account_id)->toBe($f['account']->id);
    expect($log->user_id)->toBeNull();
});

test('[GAP] sessão administrativa de outra clínica (ex.: admin explorando o painel na mesma aba) não contamina o entity_id do log do paciente — achado via auditoria de encerramento (EE-05)', function () {
    Storage::fake('s3');

    $f           = makeSharePortalFixture();
    $otherClinic = Entity::factory()->create(['is_client' => true]);

    $exam = PatientExam::factory()->create([
        'patient_id' => $f['patient']->id,
        'doctor_id'  => $f['doctor']->id,
        'active'     => true,
        'source'     => ExamSource::ExternalImport,
        'archive'    => 'exams/fake.jpg',
    ]);

    PatientDocumentShare::create([
        'entity_id'      => $f['entity']->id,
        'patient_id'     => $f['patient']->id,
        'shareable_type' => PatientExam::class,
        'shareable_id'   => $exam->id,
        'granted_at'     => now(),
    ]);

    loginAsPatient($f['account']);

    // selected_entity_id de OUTRA clínica, ainda na sessão (nunca usado
    // pelo guard de paciente, mas pode sobrar de uma sessão admin anterior
    // no mesmo navegador) — o log tem que usar a clínica DONA do exame, nunca essa.
    $this->withSession(['selected_entity_id' => $otherClinic->id])
        ->get(route('patient-portal.documents.show', ['exame', $exam->id]));

    $log = DataAccessLog::query()->latest('accessed_at')->first();

    expect($log)->not->toBeNull();
    expect($log->entity_id)->toBe($f['entity']->id)
        ->and($log->entity_id)->not->toBe($otherClinic->id);
});
