<?php

use App\Enums\{ClientRule, ExamSource, ScheduleSituation};
use App\Models\{Entity, EntityCustomDiagnosis, ExamType, Patient, PatientExam, Procedure, User, VisitType};
use App\Services\{BoundedPdfProcess, ExternalExamImportService, ScheduleService};
use App\Services\Financial\DoctorPayouts\DoctorPayoutProductionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};

/**
 * "Importar exame externo" (Gerenciador de exames): upload manual de exame
 * sem passar pelo integrador — ver ExternalExamImportController,
 * ImportExternalExamRequest e ExternalExamImportService.
 *
 * Setup espelha tests/Feature/ExamManager/ExamManagerFiltersTest.php e
 * tests/Feature/ExamManager/DiagnosisRegistrationTest.php (mesmos helpers de
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
 * POST panel.exam-manager.import.store com Accept: application/json — força
 * respostas de erro em JSON (validação/entity.role) mesmo em rota
 * Inertia-friendly, mesmo padrão usado por DiagnosisRegistrationTest para
 * as rotas do Gerenciador de exames. Upload multipart precisa de ->post()
 * (não ->postJson(), que serializa o corpo como JSON puro e quebra
 * UploadedFile).
 */
function importExternalExam($test, User $actingUser, $entityUser, array $payload)
{
    return $test->actingAs($actingUser)
        ->withSession(panelSession($entityUser))
        ->post(route('panel.exam-manager.import.store'), $payload, ['Accept' => 'application/json']);
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

    $res->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();

    expect($exam)->not->toBeNull();
    expect($exam->source)->toBe(ExamSource::ExternalImport);
    expect($exam->exam_id)->toBe($this->examType->id);
    // Importado nasce habilitado (válido para laudo, IA e repasse).
    expect($exam->active)->toBeTrue();
    Storage::disk('s3')->assertExists($exam->archive);
});

it('preserves a committed original and failed derivative status when the synchronous PDF renderer rejects it', function () {
    $file = UploadedFile::fake()->createWithContent('invalid.pdf', "%PDF-1.4\nnot a valid document\n");
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['files' => [$file]]))
        ->assertRedirect(route('panel.exam-manager.index'));
    $exam = PatientExam::where('patient_id', $this->patient->id)->sole();
    Storage::disk('s3')->assertExists($exam->archive);
    expect($exam->display_archive)->toBeNull()->and($exam->thumb_archive)->toBeNull();

    if (extension_loaded('imagick') && BoundedPdfProcess::hasIsolation()) {
        expect($exam->derivative_status)->toBe('failed')->and($exam->derivative_error_code)->toBe('derivative_generation_failed');
    } else {
        expect($exam->derivative_status)->toBe('unsupported');
    }
});

it('laterality (OD/OE) enviado no import é persistido no PatientExam; omitido fica null (AO)', function () {
    // PatientExam usa HasUuids (id não-sequencial) e os 3 imports abaixo podem
    // cair no mesmo `created_at` (precisão de timestamp do banco/CI) — usar
    // latest('created_at')->first() sem desempate é não-determinístico e pode
    // reencontrar um exame de uma chamada anterior. Rastrear os IDs já vistos
    // garante que cada asserção olha exatamente o registro recém-criado.
    $seenIds = [];

    $resOd = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['laterality' => 1]));
    $resOd->assertRedirect(route('panel.exam-manager.index'));
    $examOd = PatientExam::where('patient_id', $this->patient->id)->whereNotIn('id', $seenIds)->first();
    expect($examOd)->not->toBeNull();
    expect($examOd->laterality)->toBe(1);
    $seenIds[] = $examOd->id;

    $resOe = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['laterality' => 2]));
    $resOe->assertRedirect(route('panel.exam-manager.index'));
    $examOe = PatientExam::where('patient_id', $this->patient->id)->whereNotIn('id', $seenIds)->first();
    expect($examOe)->not->toBeNull();
    expect($examOe->laterality)->toBe(2);
    $seenIds[] = $examOe->id;

    $resNone = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this));
    $resNone->assertRedirect(route('panel.exam-manager.index'));
    $examNone = PatientExam::where('patient_id', $this->patient->id)->whereNotIn('id', $seenIds)->first();
    expect($examNone)->not->toBeNull();
    expect($examNone->laterality)->toBeNull();
});

it('laterality com valor fora de 0/1/2 retorna 422', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['laterality' => 3]));

    $res->assertStatus(422)->assertJsonValidationErrors('laterality');
});

/**
 * Olho por arquivo (Fase 5 / 7b — pedal "avançar" troca de olho ENTRE
 * capturas da mesma sessão aberta do modal). Só o SET de valores persistidos
 * no lote é verificado (não a ordem/posição exata): PatientExam usa HasUuids
 * (id não-sequencial) e created_at pode empatar entre as 3 linhas do mesmo
 * lote (mesmo problema documentado no teste de laterality acima) — o que
 * importa aqui é que as 3 ficaram DIFERENTES, não qual arquivo foi qual.
 */
it('[GAP] files_laterality (por arquivo) é persistido distinto por arquivo no mesmo lote', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files' => [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ],
        'files_laterality' => [1, 2, null],
    ]));

    $res->assertRedirect(route('panel.exam-manager.index'));

    $lateralities = PatientExam::where('patient_id', $this->patient->id)->pluck('laterality')->map(fn ($l) => $l ?? 'AO')->sort()->values()->all();
    expect($lateralities)->toBe([1, 2, 'AO']);
});

/**
 * array_key_exists vs ?? (ExternalExamImportService::import()): quando o
 * arquivo é capturado como AO, o frontend grava um null EXPLÍCITO naquele
 * índice de files_laterality (a chave existe, só o valor é null). O operador
 * ?? não distingue isso de "índice ausente" e caía pro laterality top-level
 * (última seleção da sessão do modal) — aqui o top-level é IGUAL ao olho do
 * outro arquivo de propósito, pra expor o bug: sem a correção, o arquivo 0
 * (AO) também virava 1 (OD). Só o SET é comparado (ver comentário do teste
 * de cima sobre HasUuids/created_at não-determinístico).
 */
it('[GAP] AO explícito (null) em files_laterality não é sobrescrito pelo laterality top-level/último escolhido', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'laterality' => 1, // igual ao arquivo 1 — de propósito, é o que expõe o bug
        'files'      => [
            UploadedFile::fake()->image('ao.jpg'),
            UploadedFile::fake()->image('od.jpg'),
        ],
        'files_laterality' => [null, 1], // arquivo 0 = AO explícito, arquivo 1 = OD
    ]));

    $res->assertRedirect(route('panel.exam-manager.index'));

    $lateralities = PatientExam::where('patient_id', $this->patient->id)->pluck('laterality')->map(fn ($l) => $l ?? 'AO')->sort()->values()->all();
    expect($lateralities)->toBe([1, 'AO']);
});

it('sem files_laterality (upload manual simples), continua aplicando o laterality único a todo o lote', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'laterality' => 1,
        'files'      => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ]));

    $res->assertRedirect(route('panel.exam-manager.index'));

    $exams = PatientExam::where('patient_id', $this->patient->id)->get();
    expect($exams)->toHaveCount(2);
    expect($exams->pluck('laterality')->unique()->all())->toBe([1]);
});

it('files_laterality com índice ausente cai no laterality único só pra aquele arquivo', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'laterality'       => 2,
        'files'            => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        'files_laterality' => [1], // só o índice 0 — índice 1 cai no laterality=2
    ]));

    $res->assertRedirect(route('panel.exam-manager.index'));

    $lateralities = PatientExam::where('patient_id', $this->patient->id)->pluck('laterality')->sort()->values()->all();
    expect($lateralities)->toBe([1, 2]);
});

it('[SEGURANÇA] files_laterality fora de 0/1/2 retorna 422', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files_laterality' => [9],
    ]));

    $res->assertStatus(422)->assertJsonValidationErrors('files_laterality.0');
});

it('secretary também consegue importar exame externo — gate admin/doctor/secretary, diferente do gate de diagnóstico (doctor-only)', function () {
    $res = importExternalExam($this, $this->secretary, $this->secretaryEntityUser, basePayload($this));

    $res->assertRedirect(route('panel.exam-manager.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
});

it('admin também consegue importar exame externo', function () {
    $res = importExternalExam($this, $this->admin, $this->adminEntityUser, basePayload($this));

    $res->assertRedirect(route('panel.exam-manager.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
});

it('import com múltiplos arquivos cria múltiplas linhas de PatientExam compartilhando o mesmo import_batch_id', function () {
    $files = [
        UploadedFile::fake()->image('exame1.jpg'),
        UploadedFile::fake()->image('exame2.jpg'),
        UploadedFile::fake()->create('exame3.pdf', 100, 'application/pdf'),
    ];

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['files' => $files]));

    $res->assertRedirect(route('panel.exam-manager.index'));

    $exams = PatientExam::where('patient_id', $this->patient->id)->get();

    expect($exams)->toHaveCount(3);
    expect($exams->pluck('import_batch_id')->unique())->toHaveCount(1);
    expect($exams->pluck('import_batch_id')->first())->not->toBeNull();

    foreach ($exams as $exam) {
        Storage::disk('s3')->assertExists($exam->archive);
    }
});

it('vídeo .webm da câmera (files_source=browser_capture) é aceito dentro do teto de vídeo', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'        => [UploadedFile::fake()->create('captura.webm', 15000, 'video/webm')],
        'files_source' => ['browser_capture'],
    ]));

    $res->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();
    expect($exam)->not->toBeNull()
        ->and($exam->source)->toBe(ExamSource::BrowserCapture);
    Storage::disk('s3')->assertExists($exam->archive);
});

it('vídeo .mp4 (fallback de codec) também é aceito', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'        => [UploadedFile::fake()->create('captura.mp4', 15000, 'video/mp4')],
        'files_source' => ['browser_capture'],
    ]));

    $res->assertRedirect(route('panel.exam-manager.index'));
    expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
});

it('vídeo maior que o teto de vídeo configurado retorna 422, imagem continua limitada a 10MB', function () {
    config(['eye_image_capture.capture.max_video_kb' => 20480]);

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files' => [UploadedFile::fake()->create('captura.webm', 20481, 'video/webm')],
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('files.0');
    expect(PatientExam::count())->toBe(0);
});

it('[GAP] vídeo de 11MB de verdade (dentro do teto de vídeo, acima do teto de leitura de derivados): deriva "unsupported", nunca "failed" (achado via revisão Codex)', function () {
    // UploadedFile::fake()->create($nome, $kilobytes, $mime) só FINGE o
    // tamanho (sizeToReport) — o arquivo de verdade fica vazio (tmpfile()
    // nunca escrito), então nunca bateria no teto de leitura de
    // BoundedExamArchive::read() de propósito. createWithContent() (2º
    // argumento string, não int) escreve bytes de verdade — aqui precisa
    // ser um vídeo realmente grande, não só "declarado" grande.
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'        => [UploadedFile::fake()->create('captura.webm', str_repeat('x', 11 * 1024 * 1024))],
        'files_source' => ['browser_capture'],
    ]));

    $res->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->sole();
    Storage::disk('s3')->assertExists($exam->archive);
    expect($exam->display_archive)->toBeNull()->and($exam->thumb_archive)->toBeNull();

    // Sem a correção, BoundedExamArchive::read() (teto padrão de 10MB) lança
    // archive_size_limit ANTES da extensão ser checada — um vídeo válido
    // (dentro do teto PRÓPRIO de vídeo, até 20MB por padrão) virava job
    // falho em vez de simplesmente "sem derivado", igual qualquer outro
    // formato sem suporte (achado via revisão Codex).
    expect($exam->derivative_status)->toBe('unsupported')
        ->and($exam->derivative_error_code)->toBe('format_no_derivative');
});

it('sem source informado, continua external_import (comportamento de sempre)', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this))
        ->assertRedirect(route('panel.exam-manager.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->first()->source)->toBe(ExamSource::ExternalImport);
});

it('[SEGURANÇA] files_source=integrator (reservado ao pipeline real) é rejeitado com 422', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files_source' => ['integrator'],
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('files_source.0');
    expect(PatientExam::count())->toBe(0);
});

/*
 * Origem POR ARQUIVO (13ª rodada de revisão adversarial Codex): o formulário
 * permite lote misto (scan externo + foto da câmera), mas mandava UMA origem
 * pro lote — a foto fazia o scan externo virar "captura da clínica", com
 * vínculo ao agendamento e como produção pagável no repasse; desativar a
 * captura de verdade depois não tirava o scan do repasse.
 */
it('[PROVENIÊNCIA] lote misto: cada arquivo guarda a PRÓPRIA origem e só a captura é vinculada ao agendamento atendido', function () {
    $examDay                                       = now()->subDay();
    ['schedule' => $schedule, 'doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
    ]);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'doctor_id'         => $doctor->id,
        'files'             => [UploadedFile::fake()->image('scan-externo.jpg'), UploadedFile::fake()->image('captura.jpg')],
        // O olho identifica o arquivo (OD = scan externo, OE = captura).
        'files_laterality' => [1, 2],
        'files_source'     => [ExamSource::ExternalImport->value, ExamSource::BrowserCapture->value],
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $scan    = PatientExam::where('patient_id', $this->patient->id)->where('laterality', 1)->sole();
    $capture = PatientExam::where('patient_id', $this->patient->id)->where('laterality', 2)->sole();

    expect($scan->source)->toBe(ExamSource::ExternalImport)
        ->and($scan->schedule_id)->toBeNull()
        ->and($capture->source)->toBe(ExamSource::BrowserCapture)
        ->and((string) $capture->schedule_id)->toBe((string) $schedule->id)
        ->and($scan->import_batch_id)->toBe($capture->import_batch_id);
});

it('[REPASSE] scan externo de um lote misto nunca vira produção: com a captura desativada o repasse do médico zera', function () {
    $examDay = CarbonImmutable::now()->subDay();
    $doctor  = createDoctorForEntity($this->entity);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'doctor_id'         => $doctor->id,
        'files'             => [UploadedFile::fake()->image('scan-externo.jpg'), UploadedFile::fake()->image('captura.jpg')],
        'files_laterality'  => [1, 2],
        'files_source'      => [ExamSource::ExternalImport->value, ExamSource::BrowserCapture->value],
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $acts = fn () => app(DoctorPayoutProductionService::class)->acts(
        $this->entity->id,
        (string) $doctor->id,
        $examDay->subDay(),
        $examDay->addDay(),
    );

    // Só a captura é produção da clínica (um ato: paciente + tipo + dia).
    expect($acts())->toHaveCount(1);

    // O médico desabilita a captura (foto ruim): o scan externo não pode assumir o ato.
    PatientExam::where('patient_id', $this->patient->id)->where('laterality', 2)->update(['active' => false]);

    expect($acts())->toHaveCount(0);
});

it('lote só de capturas (várias fotos): todas browser_capture, todas vinculadas ao agendamento atendido', function () {
    $examDay                                       = now()->subDay();
    ['schedule' => $schedule, 'doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
    ]);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'doctor_id'         => $doctor->id,
        'files'             => [UploadedFile::fake()->image('c1.jpg'), UploadedFile::fake()->image('c2.jpg'), UploadedFile::fake()->image('c3.jpg')],
        'files_source'      => array_fill(0, 3, ExamSource::BrowserCapture->value),
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $exams = PatientExam::where('patient_id', $this->patient->id)->get();

    expect($exams)->toHaveCount(3)
        ->and($exams->pluck('source')->unique()->all())->toBe([ExamSource::BrowserCapture])
        ->and($exams->pluck('schedule_id')->map(fn ($id) => (string) $id)->unique()->all())->toBe([(string) $schedule->id]);
});

it('[PROVENIÊNCIA] sem files_source, todo arquivo é external_import — na dúvida, nunca vira captura da clínica', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ]))->assertRedirect(route('panel.exam-manager.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->get()->pluck('source')->unique()->all())->toBe([ExamSource::ExternalImport]);
});

it('[PROVENIÊNCIA] lista de origens mais curta que a de arquivos: o que não foi declarado é external_import; entradas além do último arquivo são ignoradas', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'            => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
        'files_laterality' => [1, 2, 0],
        // 1º declarado como captura; 2º vazio; 3º sem entrada; 4º e 5º sem arquivo.
        'files_source' => [ExamSource::BrowserCapture->value, '', null, ExamSource::BrowserCapture->value, ExamSource::BrowserCapture->value],
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $bySide = PatientExam::where('patient_id', $this->patient->id)->get()->keyBy('laterality');

    expect($bySide)->toHaveCount(3)
        ->and($bySide[1]->source)->toBe(ExamSource::BrowserCapture)
        ->and($bySide[2]->source)->toBe(ExamSource::ExternalImport)
        ->and($bySide[0]->source)->toBe(ExamSource::ExternalImport);
});

it('[SEGURANÇA] origem desconhecida num arquivo do lote é rejeitada com 422 e nada é criado', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'        => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        'files_source' => [ExamSource::ExternalImport->value, 'camera'],
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('files_source.1');
    expect(PatientExam::count())->toBe(0);
});

it('[SEGURANÇA] files_source que não é lista (valor único) é rejeitado com 422 — não vale pro lote inteiro', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'        => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        'files_source' => ExamSource::BrowserCapture->value,
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('files_source');
    expect(PatientExam::count())->toBe(0);
});

it('[SEGURANÇA] campo único `source` do formulário antigo é recusado (422) com orientação de recarregar — não adivinha a origem de cada arquivo', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'  => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        'source' => ExamSource::BrowserCapture->value,
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('source');
    expect($res->json('errors.source.0'))->toBe(__('eye_images.import_source_outdated'))
        ->and(PatientExam::count())->toBe(0);
});

it('formulário antigo mandando source vazio (upload puro) continua importando como external_import', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['source' => '']))
        ->assertRedirect(route('panel.exam-manager.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->sole()->source)->toBe(ExamSource::ExternalImport);
});

it('[SEGURANÇA] o serviço não promove origem fora de external_import/browser_capture (defesa em profundidade além do request)', function () {
    $exams = app(ExternalExamImportService::class)->import(
        (string) $this->entity->id,
        [
            'patient_id'        => $this->patient->id,
            'exam_id'           => $this->examType->id,
            'exam_performed_at' => now()->subDay()->toDateString(),
            'files_source'      => [ExamSource::Integrator->value],
        ],
        [UploadedFile::fake()->image('a.jpg')],
        (string) $this->doctor->id,
    );

    expect($exams->sole()->source)->toBe(ExamSource::ExternalImport);
});

it('importação só de scans externos não toca em agendamentos (nenhum lock de paciente/dia nem de linha de agenda)', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'        => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        'files_source' => [ExamSource::ExternalImport->value, ExamSource::ExternalImport->value],
    ]))->assertRedirect(route('panel.exam-manager.index'));

    expect(collect($queries)->filter(
        fn (string $sql) => str_contains($sql, 'pg_advisory') || (str_contains($sql, 'from "schedules"') && str_contains($sql, 'for update')),
    )->all())->toBe([]);
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

it('arquivo maior que 10MB retorna 422', function () {
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files' => [UploadedFile::fake()->create('exame.jpg', 10241, 'image/jpeg')],
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('files.0');

    expect(PatientExam::count())->toBe(0);
});

it('[GAP] imagem renomeada pra .mp4 continua presa ao teto de 10MB de imagem (nunca ao teto maior de vídeo)', function () {
    // Nome do arquivo (.mp4) sugere vídeo, mas o conteúdo de verdade (MIME
    // image/png aqui, simulando o conteúdo real de um PNG) é imagem — o
    // teto certo é o de imagem (10 MB), nunca o de vídeo (bem maior,
    // configurável). Sem a correção, getClientOriginalExtension() confiava
    // só no nome e deixava 15 MB passarem (achado via revisão Codex).
    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files' => [UploadedFile::fake()->create('grande.mp4', 15360, 'image/png')],
    ]));

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('files.0');

    expect(PatientExam::count())->toBe(0);
});

it('[GAP] imagem renomeada pra .mp4 (dentro do teto, passa a validação) é salva no S3 com extensão de IMAGEM — isVideo() nunca confunde depois (achado via revisão Codex)', function () {
    // createWithContent (2º argumento string) escreve bytes de verdade —
    // precisa pra chegar até storeArchive(), diferente do teste de teto
    // acima (que nem chega lá, 422 na validação). mimeTypeToReport
    // sobrescrito manualmente: createWithContent() nunca aceita um 3º
    // argumento de MIME (só o create() com $kilobytes inteiro aceita).
    $file                   = UploadedFile::fake()->create('foto.mp4', 'conteudo-de-imagem-fake-mas-com-bytes-de-verdade');
    $file->mimeTypeToReport = 'image/png';

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, ['files' => [$file]]));
    $res->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->sole();
    // Sem a correção, storeArchive() usava getClientOriginalExtension()
    // (nome do arquivo, 100% controlado pelo cliente) — salvava com .mp4
    // mesmo o conteúdo validado sendo imagem, e isVideo() (extensão do
    // archive) classificava errado: a tela tentava tocar um <video>
    // quebrado em vez de mostrar a imagem que sempre funcionou.
    expect($exam->archive)->not->toEndWith('.mp4')
        ->and($exam->isVideo())->toBeFalse();
});

it('[GAP] vídeo de verdade renomeado pra .jpg é salvo no S3 com extensão de VÍDEO — isVideo() reconhece corretamente (achado via revisão Codex)', function () {
    $file                   = UploadedFile::fake()->create('captura.jpg', 'conteudo-de-video-fake-mas-com-bytes-de-verdade');
    $file->mimeTypeToReport = 'video/webm';

    $res = importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'files'        => [$file],
        'files_source' => ['browser_capture'],
    ]));
    $res->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->sole();
    // Inverso do teste acima: um vídeo de verdade renomeado pra .jpg não
    // pode ser salvo/classificado como imagem — a tela tentaria decodificar
    // bytes de vídeo como raster.
    expect($exam->archive)->toEndWith('.webm')
        ->and($exam->isVideo())->toBeTrue();
});

it('exame importado aparece na listagem do Gerenciador de exames (index/search) marcado com source=external_import', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this))
        ->assertRedirect(route('panel.exam-manager.index'));

    $searchRes = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.search'));

    $searchRes->assertOk();

    $exams = collect($searchRes->json('patients.0.exams'));
    expect($exams)->toHaveCount(1);
    expect($exams->first()['source'])->toBe(ExamSource::ExternalImport->value);
    expect($exams->first()['is_external'])->toBeTrue();

    $indexRes = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->get(route('panel.exam-manager.index'));

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

    $res->assertRedirect(route('panel.exam-manager.index'));

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
        ->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();

    expect($exam->created_by)->toBe($this->secretary->id);
});

it('URL assinada de leitura (patientExamUrls) funciona pro exame importado igual a um exame nativo', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this))
        ->assertRedirect(route('panel.exam-manager.index'));

    $imported = PatientExam::where('patient_id', $this->patient->id)->first();

    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.patient-urls', ['patient' => $this->patient->id]));

    $res->assertOk();

    $urls = $res->json('urls');
    expect($urls)->toHaveKey($imported->id);
    expect($urls[$imported->id])->toBeString()->not->toBeEmpty();
});

it('URL assinada de leitura (imageUrl) funciona pro exame importado igual a um exame nativo', function () {
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this))
        ->assertRedirect(route('panel.exam-manager.index'));

    $imported = PatientExam::where('patient_id', $this->patient->id)->first();

    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.image-url', ['exam' => $imported->id]));

    $res->assertOk();
    expect($res->json('url'))->toBeString()->not->toBeEmpty();
});

/*
 * Captura pela câmera (browser_capture) conta como produção da clínica pro
 * repasse médico (DoctorPayoutProductionService) — diferente da importação
 * manual (external_import), que fica de fora de propósito. Sem schedule_id,
 * a MESMA consulta já atendida virava 2 atos: o agendamento E o "exame de
 * equipamento sem vínculo" — achado via revisão adversarial Codex.
 */
it('[GAP] captura pela câmera vincula ao agendamento atendido do mesmo dia/médico — achado via revisão adversarial Codex', function () {
    $examDay                                       = now()->subDay();
    ['schedule' => $schedule, 'doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
    ]);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $doctor->id,
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();
    expect((string) $exam->schedule_id)->toBe((string) $schedule->id);
});

it('[GAP] importação manual (external_import) nunca vincula a agendamento, mesmo com um atendido disponível — fora da produção de propósito', function () {
    $examDay = now()->subDay();
    createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
    ]);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();
    expect($exam->schedule_id)->toBeNull();
});

it('[GAP] captura pela câmera sem agendamento atendido correspondente fica sem vínculo (nenhum, médico diferente, ou ambíguo) — nunca um palpite errado', function () {
    $examDay = now()->subDay();

    // Nenhum agendamento atendido pro paciente.
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
    ]))->assertRedirect(route('panel.exam-manager.index'));
    expect(PatientExam::where('patient_id', $this->patient->id)->first()->schedule_id)->toBeNull();
    PatientExam::query()->delete();

    // Agendamento atendido existe, mas de OUTRO médico — nunca vincula ao
    // agendamento de um médico diferente do informado na captura.
    createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
    ]);
    $otherDoctor = createDoctorForEntity($this->entity);
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $otherDoctor->id,
    ]))->assertRedirect(route('panel.exam-manager.index'));
    expect(PatientExam::where('patient_id', $this->patient->id)->where('doctor_id', $otherDoctor->id)->first()->schedule_id)->toBeNull();
});

it('[GAP] 2 agendamentos atendidos no mesmo dia pro mesmo paciente: ambíguo demais pra vincular sozinho, fica sem vínculo', function () {
    $examDay              = now()->subDay()->setTime(10, 0);
    ['doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
    ]);
    createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'doctor_id'  => $doctor->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay->copy()->addHour(),
    ]);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $doctor->id,
    ]))->assertRedirect(route('panel.exam-manager.index'));

    expect(PatientExam::where('patient_id', $this->patient->id)->first()->schedule_id)->toBeNull();
});

it('[GAP] agendamento de exame atendido + captura pela câmera pro mesmo atendimento gera só 1 ato de repasse médico, nunca 2 — achado via revisão adversarial Codex', function () {
    $examType = ExamType::factory()->create(['entity_id' => null, 'name' => 'OCT']);
    $examDay  = CarbonImmutable::now()->subDay();

    // Agendamento de EXAME (treatment=3) atendido — mesmo setup conceitual
    // de DoctorPayoutProductionServiceTest (visit_type->procedure.treatment).
    $procedure                                     = Procedure::factory()->create(['treatment' => 3]);
    $visitType                                     = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'OCT', 'procedure_id' => $procedure->id, 'active' => true]);
    ['schedule' => $schedule, 'doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
        'visit_id'   => $visitType->id,
    ]);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_id'           => $examType->id,
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $doctor->id,
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $acts = app(DoctorPayoutProductionService::class)->acts(
        $this->entity->id,
        (string) $doctor->id,
        $examDay->subDay(),
        $examDay->addDay(),
    );

    expect($acts)->toHaveCount(1)
        ->and($acts->first()->sourceId)->toBe((string) $schedule->id);
});

it('[GAP] captura pela câmera ANTES do agendamento virar Atendido fica sem vínculo até a mudança de situação reconciliar — achado via revisão adversarial Codex', function () {
    $examDay                                       = now()->subDay();
    ['schedule' => $schedule, 'doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Waiting->value,
        'date_time'  => $examDay,
    ]);

    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $doctor->id,
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();
    expect($exam->schedule_id)->toBeNull();

    app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::Attended, $this->doctorEntityUser->id);

    expect($exam->refresh()->schedule_id)->toBe($schedule->id);
});

it('[GAP] captura pela câmera ANTES do atendimento + agendamento de exame que vira Atendido depois gera só 1 ato de repasse médico, nunca 2 — achado via revisão adversarial Codex', function () {
    $examType = ExamType::factory()->create(['entity_id' => null, 'name' => 'OCT']);
    $examDay  = CarbonImmutable::now()->subDay();

    $procedure                                     = Procedure::factory()->create(['treatment' => 3]);
    $visitType                                     = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'OCT', 'procedure_id' => $procedure->id, 'active' => true]);
    ['schedule' => $schedule, 'doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Waiting->value,
        'date_time'  => $examDay,
        'visit_id'   => $visitType->id,
    ]);

    // Captura feita enquanto o paciente ainda está Aguardando — nada pra
    // vincular ainda, igual ao teste anterior.
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_id'           => $examType->id,
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $doctor->id,
    ]))->assertRedirect(route('panel.exam-manager.index'));

    app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::Attended, $this->doctorEntityUser->id);

    $acts = app(DoctorPayoutProductionService::class)->acts(
        $this->entity->id,
        (string) $doctor->id,
        $examDay->subDay(),
        $examDay->addDay(),
    );

    expect($acts)->toHaveCount(1)
        ->and($acts->first()->sourceId)->toBe((string) $schedule->id);
});

it('[GAP] ambiguidade entre agendamentos atendidos também bloqueia a reconciliação feita na mudança de situação, nunca um palpite errado', function () {
    $examDay              = now()->subDay()->setTime(10, 0);
    ['doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay,
    ]);
    createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'doctor_id'  => $doctor->id,
        'situation'  => ScheduleSituation::Attended->value,
        'date_time'  => $examDay->copy()->addHour(),
    ]);

    // Já ambíguo na importação (2 agendamentos atendidos no mesmo dia pro
    // mesmo médico) — fica sem vínculo, igual ao teste de ambiguidade acima.
    importExternalExam($this, $this->doctor, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => $examDay->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $doctor->id,
    ]))->assertRedirect(route('panel.exam-manager.index'));

    $exam = PatientExam::where('patient_id', $this->patient->id)->first();
    expect($exam->schedule_id)->toBeNull();

    // Um TERCEIRO agendamento do mesmo médico/paciente/dia vira Atendido
    // depois — a reconciliação dispara, mas a ambiguidade (agora 3
    // atendidos no mesmo dia) continua bloqueando o vínculo automático.
    ['schedule' => $thirdSchedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'doctor_id'  => $doctor->id,
        'situation'  => ScheduleSituation::Waiting->value,
        'date_time'  => $examDay->copy()->addHours(2),
    ]);

    app(ScheduleService::class)->changeSituation($thirdSchedule, ScheduleSituation::Attended, $this->doctorEntityUser->id);

    expect($exam->refresh()->schedule_id)->toBeNull();
});
