<?php

use App\Enums\{ClientRule, ExamSource};
use App\Models\{Entity, EntityCustomDiagnosis, ExamType, Patient, PatientExam, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * "Importar exame externo" (Gerenciador de Imagens): upload manual de exame
 * sem passar pelo integrador — ver ExternalExamImportController,
 * ImportExternalExamRequest e ExternalExamImportService.
 *
 * Setup espelha tests/Feature/EyeImages/EyeImagesFiltersTest.php e
 * tests/Feature/EyeImages/DiagnosisRegistrationTest.php (mesmos helpers de
 * Pest.php: createEntityUser, panelSession). Storage::fake('s3') segue o
 * mesmo padrão de tests/Feature/Api/Integrators/PatientExamsTest.php.
 */
beforeEach(function () {
    Storage::fake('s3');

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctor           = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctor, ClientRule::Doctor->value);

    $this->secretary           = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretary, ClientRule::Secretary->value);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->examType = ExamType::factory()->create(['entity_id' => null]);
});

/**
 * POST panel.eye-images.import.store com Accept: application/json — força
 * respostas de erro em JSON (validação/entity.role) mesmo em rota
 * Inertia-friendly, mesmo padrão usado por DiagnosisRegistrationTest para
 * as rotas do Gerenciador de Imagens. Upload multipart precisa de ->post()
 * (não ->postJson(), que serializa o corpo como JSON puro e quebra
 * UploadedFile).
 */
function importExternalExam($test, User $actingUser, $entityUser, array $payload)
{
    return $test->actingAs($actingUser)
        ->withSession(panelSession($entityUser))
        ->post(route('panel.eye-images.import.store'), $payload, ['Accept' => 'application/json']);
}

function basePayload($test, array $overrides = []): array
{
    return array_merge([
        'patient_id'        => $test->patient->id,
        'exam_id'           => $test->examType->id,
        'exam_performed_at' => now()->subDay()->toDateString(),
        'files'             => [UploadedFile::fake()->image('exame.jpg')],
    ], $overrides);
}

it('médico importa exame externo com 1 arquivo — PatientExam criado com source=external_import e arquivo no disco fake', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this));

    $res->assertRedirect(route('panel.eye-images.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();

    expect($exam)->not->toBeNull();
    expect($exam->source)->toBe(ExamSource::ExternalImport);
    expect($exam->exam_id)->toBe($this->examType->id);
    Storage::disk('s3')->assertExists($exam->archive);
});

it('secretary também consegue importar exame externo — gate admin/doctor/secretary, diferente do gate de diagnóstico (doctor-only)', function () {
    $res = importExternalExam($this, $this->secretary, $this->secretaryEntityUser, basePayload($this));

    $res->assertRedirect(route('panel.eye-images.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
});

it('admin também consegue importar exame externo', function () {
    $res = importExternalExam($this, $this->admin, $this->adminEntityUser, basePayload($this));

    $res->assertRedirect(route('panel.eye-images.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
});

it('import com múltiplos arquivos cria múltiplas linhas de PatientExam compartilhando o mesmo import_batch_id', function () {
    $files = [
        UploadedFile::fake()->image('exame1.jpg'),
        UploadedFile::fake()->image('exame2.jpg'),
        UploadedFile::fake()->create('exame3.pdf', 100, 'application/pdf'),
    ];

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['files' => $files]));

    $res->assertRedirect(route('panel.eye-images.index'));

    $exams = PatientExam::where('patient_id', $this->patient->id)->get();

    expect($exams)->toHaveCount(3);
    expect($exams->pluck('import_batch_id')->unique())->toHaveCount(1);
    expect($exams->pluck('import_batch_id')->first())->not->toBeNull();

    foreach ($exams as $exam) {
        Storage::disk('s3')->assertExists($exam->archive);
    }
});

it('patient_id de outra entidade é rejeitado (422, isolamento multi-tenant) e não cria exame', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'patient_id' => $otherPatient->id,
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('patient_id');

    expect(PatientExam::where('patient_id', $otherPatient->id)->exists())->toBeFalse();
    expect(PatientExam::count())->toBe(0);
});

it('custom_diagnosis_id de outra entidade é rejeitado (422, isolamento multi-tenant) e não cria exame', function () {
    $otherEntity    = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherDiagnosis = EntityCustomDiagnosis::create([
        'entity_id'       => $otherEntity->id,
        'name'            => 'Diagnóstico sigiloso da outra clínica',
        'normalized_name' => 'diagnóstico sigiloso da outra clínica',
    ]);

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'diagnoses' => [
            ['custom_diagnosis_id' => (string) $otherDiagnosis->id, 'description' => 'texto qualquer', 'is_primary' => true],
        ],
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('diagnoses.0.custom_diagnosis_id');

    expect(PatientExam::count())->toBe(0);
});

it('campos obrigatórios ausentes (patient_id, exam_id, exam_performed_at, files) retornam 422', function () {
    $payload = basePayload($this);
    unset($payload['patient_id'], $payload['exam_id'], $payload['exam_performed_at'], $payload['files']);

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, $payload);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors(['patient_id', 'exam_id', 'exam_performed_at', 'files']);

    expect(PatientExam::count())->toBe(0);
});

it('arquivo com mimetype não permitido (.exe) retorna 422', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files' => [UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload')],
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('files.0');

    expect(PatientExam::count())->toBe(0);
});

it('exame importado aparece na listagem do Gerenciador de Imagens (index/search) marcado com source=external_import', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this))
        ->assertRedirect(route('panel.eye-images.index'));

    $searchRes = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.eye-images.search'));

    $searchRes->assertOk();

    $exams = collect($searchRes->json('patients.0.exams'));
    expect($exams)->toHaveCount(1);
    expect($exams->first()['source'])->toBe(ExamSource::ExternalImport->value);
    expect($exams->first()['is_external'])->toBeTrue();

    $indexRes = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->get(route('panel.eye-images.index'));

    $indexRes->assertOk();
    $indexRes->assertInertia(fn ($page) => $page
        ->where('patients.0.exams.0.source', ExamSource::ExternalImport->value)
        ->where('patients.0.exams.0.is_external', true));
});

it('diagnoses[] enviado no import é persistido em diagnosis_cids em todas as linhas do batch igualmente', function () {
    $files = [
        UploadedFile::fake()->image('exame1.jpg'),
        UploadedFile::fake()->image('exame2.jpg'),
    ];

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'     => $files,
        'diagnoses' => [
            ['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto', 'is_primary' => true],
        ],
    ]));

    $res->assertRedirect(route('panel.eye-images.index'));

    $exams = PatientExam::where('patient_id', $this->patient->id)->get();
    expect($exams)->toHaveCount(2);

    foreach ($exams as $exam) {
        expect($exam->diagnosis_cids)->toHaveCount(1);
        expect($exam->diagnosis_cids[0])->toMatchArray([
            'code'        => 'H40.1',
            'description' => 'Glaucoma primário de ângulo aberto',
            'is_primary'  => true,
        ]);
    }
});

it('created_by do PatientExam importado é preenchido com o usuário autenticado que fez o import', function () {
    importExternalExam($this, $this->secretary, $this->secretaryEntityUser, basePayload($this))
        ->assertRedirect(route('panel.eye-images.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();

    expect($exam->created_by)->toBe($this->secretary->id);
});

it('URL assinada de leitura (patientExamUrls) funciona pro exame importado igual a um exame nativo', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this))
        ->assertRedirect(route('panel.eye-images.index'));

    $imported = PatientExam::where('patient_id', $this->patient->id)->first();

    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.eye-images.patient-urls', ['patient' => $this->patient->id]));

    $res->assertOk();

    $urls = $res->json('urls');
    expect($urls)->toHaveKey($imported->id);
    expect($urls[$imported->id])->toBeString()->not->toBeEmpty();
});

it('URL assinada de leitura (imageUrl) funciona pro exame importado igual a um exame nativo', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this))
        ->assertRedirect(route('panel.eye-images.index'));

    $imported = PatientExam::where('patient_id', $this->patient->id)->first();

    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.eye-images.image-url', ['exam' => $imported->id]));

    $res->assertOk();
    expect($res->json('url'))->toBeString()->not->toBeEmpty();
});
