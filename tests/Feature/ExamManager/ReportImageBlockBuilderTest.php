<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Doctor, Entity, ExamType, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, User};
use App\Services\EyeImages\ReportImageBlockBuilder;
use App\Services\RasterMemoryBudget;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCheckFileExistence;

/**
 * Fase 5 / 5c — resolve metadata.image_report pra bytes base64 embutidos no
 * PDF (nunca URL remota: wkhtmltopdf sem acesso de rede ao bucket).
 * Tolerante a tudo que mudou DEPOIS da emissão (exame excluído/desabilitado/
 * ilegível) — nunca quebra a reimpressão.
 */
beforeEach(function () {
    Storage::fake('s3');
    $this->builder = app(ReportImageBlockBuilder::class);

    $this->entity = Entity::factory()->create(['is_client' => true]);
    $doctorEu     = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor = Doctor::query()->create([
        'entity_user_id' => $doctorEu->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);
});

function reportImageFakeJpeg(int $w = 40, int $h = 30): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function reportImageTruncatedPng(int $w = 40, int $h = 30): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    // Assinatura (8 bytes) + chunk IHDR completo (4 tamanho + 4 tipo +
    // 13 dados + 4 CRC = 25) = 33 bytes: cabeçalho válido (dimensões
    // legíveis por getimagesizefromstring()), mas sem nenhum chunk IDAT —
    // imagecreatefromstring() não decodifica. Mesma reprodução do achado
    // na 11ª rodada de revisão adversarial Codex ("33-byte truncated PNG").
    return substr($bytes, 0, 33);
}

function docWithImageBlock(object $test, array $block): MedicalRecordDocumentation
{
    return MedicalRecordDocumentation::create([
        'medical_record_id' => $test->record->id,
        'patient_id'        => $test->patient->id,
        'doctor_id'         => $test->doctor->id,
        'type'              => DocumentationType::Report->value,
        'content'           => '<p>Achados.</p>',
        'metadata'          => ['image_report' => $block],
    ]);
}

it('sem metadata.image_report devolve null', function () {
    $doc = MedicalRecordDocumentation::create([
        'medical_record_id' => $this->record->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Report->value,
        'content'           => '<p>Sem bloco.</p>',
    ]);

    expect($this->builder->build($doc))->toBeNull();
});

it('resolve o display_archive em data: URI base64 quando columns < 4', function () {
    $exam = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/e1_display.jpg',
    ]);
    Storage::disk('s3')->put('exams/e1_display.jpg', reportImageFakeJpeg());

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'columns' => 2, 'pair_od_oe' => false,
        'images'  => [['exam_id' => $exam->id, 'caption' => 'Legenda', 'order' => 0]],
    ]);

    $result = $this->builder->build($doc);

    expect($result['columns'])->toBe(2)
        ->and($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['caption'])->toBe('Legenda')
        ->and($result['images'][0]['data_uri'])->toStartWith('data:image/jpeg;base64,');
});

it('usa thumb_archive (não display_archive) a partir de 4 colunas', function () {
    $exam = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/e1_display.jpg',
        'thumb_archive'   => 'exams/e1_thumb.jpg',
    ]);
    // Bytes DIFERENTES — dá pra provar qual arquivo foi lido pelo tamanho decodificado.
    Storage::disk('s3')->put('exams/e1_display.jpg', reportImageFakeJpeg(200, 150));
    Storage::disk('s3')->put('exams/e1_thumb.jpg', reportImageFakeJpeg(10, 8));

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'columns' => 4, 'pair_od_oe' => false,
        'images'  => [['exam_id' => $exam->id, 'order' => 0]],
    ]);

    $thumbUri   = $this->builder->build($doc)['images'][0]['data_uri'];
    $thumbBytes = base64_decode(substr($thumbUri, strlen('data:image/jpeg;base64,')));
    expect(getimagesizefromstring($thumbBytes))->toMatchArray([0 => 10, 1 => 8]);
});

it('exame excluído (ativo=false) desde a emissão não aparece, sem quebrar', function () {
    $exam = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/e1_display.jpg',
        'active'          => false,
    ]);
    Storage::disk('s3')->put('exams/e1_display.jpg', reportImageFakeJpeg());

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'images' => [['exam_id' => $exam->id, 'order' => 0]],
    ]);

    expect($this->builder->build($doc))->toBeNull();
});

it('[GAP] arquivo que existe mas falha ao ler (get() devolve null) não quebra o laudo inteiro', function () {
    // Disco S3 é configurado com throw:false (config/filesystems.php) — uma
    // leitura que falhar (arquivo some entre exists() e get(), rede,
    // permissão) devolve null em vez de lançar. Storage::fake('s3') é um
    // disco local de verdade, sem jeito de simular essa falha específica
    // via put()/delete(); mocka Storage::disk('s3') direto pra forçar
    // exists()=true + get()=null no MESMO path.
    // 'archive' é NOT NULL no banco (sempre existe, mesmo path aleatório do
    // factory) — o mock trata só 'exams/unreadable.jpg' (display_archive,
    // candidato prioritário com columns<4) como existente; o 'archive'
    // aleatório cai no exists()=false normal, igual a um arquivo que nunca
    // existiu, então o loop não tenta ler ele.
    $exam = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/unreadable.jpg',
    ]);

    $diskDouble = Mockery::mock();
    $diskDouble->shouldReceive('exists')->andReturnUsing(fn ($path) => $path === 'exams/unreadable.jpg');
    // size() entra no mesmo try/catch de exists(), antes do get() — precisa
    // de stub aqui também, mesmo não sendo o foco deste teste.
    $diskDouble->shouldReceive('size')->with('exams/unreadable.jpg')->andReturn(100);
    $diskDouble->shouldReceive('get')->with('exams/unreadable.jpg')->andReturn(null);
    Storage::shouldReceive('disk')->with('s3')->andReturn($diskDouble);

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'images' => [['exam_id' => $exam->id, 'order' => 0]],
    ]);

    // Não pode lançar TypeError (getimagesizefromstring(null) sob strict_types)
    // — o item ilegível some do bloco, mesma filosofia "tolerante" já aplicada
    // a exame excluído/removido (acima) e a arquivo não-raster (abaixo).
    expect($this->builder->build($doc))->toBeNull();
});

it('[GAP] exists() lançando (S3 fora do ar / HeadObject negado) não quebra o laudo inteiro — item cai, resto do bloco continua', function () {
    // exists() NUNCA respeita throw:false (config/filesystems.php):
    // Storage::exists() delega direto pro adapter via Flysystem\Filesystem::
    // has(), sem o try/catch que FilesystemAdapter aplica em get()/put()/etc.
    // (throwsExceptions()) — S3 fora do ar, rede, ou política de bucket
    // negando HeadObject lança UnableToCheckFileExistence por cima do
    // throw:false. Sem o catch em resolveDataUri(), UMA imagem inacessível
    // quebrava o laudo inteiro, inclusive pelo link público assinado do
    // médico solicitante (achado via revisão Codex).
    $examFails = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/fails_display.jpg',
    ]);
    $examOk = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/ok_display.jpg',
    ]);

    $diskDouble = Mockery::mock();
    $diskDouble->shouldReceive('exists')->andReturnUsing(function ($path) {
        if ($path === 'exams/fails_display.jpg') {
            throw UnableToCheckFileExistence::forLocation($path);
        }

        return $path === 'exams/ok_display.jpg';
    });
    // size() entra no mesmo try/catch de exists(), antes do get() — precisa
    // de stub aqui também, mesmo não sendo o foco deste teste.
    $diskDouble->shouldReceive('size')->with('exams/ok_display.jpg')->andReturn(100);
    $diskDouble->shouldReceive('get')->with('exams/ok_display.jpg')->andReturn(reportImageFakeJpeg());
    Storage::shouldReceive('disk')->with('s3')->andReturn($diskDouble);

    $doc = docWithImageBlock($this, [
        'enabled' => true,
        'images'  => [
            ['exam_id' => $examFails->id, 'order' => 0],
            ['exam_id' => $examOk->id, 'order' => 1],
        ],
    ]);

    $result = $this->builder->build($doc);

    expect($result)->not->toBeNull()
        ->and($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['data_uri'])->toStartWith('data:image/jpeg;base64,');
});

it('exame removido do banco não aparece, sem quebrar', function () {
    $doc = docWithImageBlock($this, [
        'enabled' => true, 'images' => [['exam_id' => (string) Str::uuid(), 'order' => 0]],
    ]);

    expect($this->builder->build($doc))->toBeNull();
});

it('arquivo que não é imagem raster (vídeo) nunca entra no bloco', function () {
    $video = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/clip.webm',
    ]);
    Storage::disk('s3')->put('exams/clip.webm', 'não é bytes de imagem de verdade');

    $photo = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/photo_display.jpg',
    ]);
    Storage::disk('s3')->put('exams/photo_display.jpg', reportImageFakeJpeg());

    $doc = docWithImageBlock($this, [
        'enabled' => true,
        'images'  => [
            ['exam_id' => $video->id, 'order' => 0],
            ['exam_id' => $photo->id, 'order' => 1],
        ],
    ]);

    $result = $this->builder->build($doc);
    expect($result['images'])->toHaveCount(1);
});

it('[GAP] imagem com dimensão acima do teto (bomba de descompressão) nunca entra no laudo, mesmo cabendo no orçamento de bytes', function () {
    // Mesmo teto de GenerateExamDerivatives::imageFromRaster() (16000px por
    // lado / 40 megapixels). 16001×1 é fina de propósito — gera o fixture
    // sem gastar memória de verdade no teste (ao contrário de uma imagem
    // gigante de verdade), mas ainda assim excede o teto de largura.
    $giant = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/gigante.jpg',
    ]);
    Storage::disk('s3')->put('exams/gigante.jpg', reportImageFakeJpeg(16001, 1));

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'images' => [['exam_id' => $giant->id, 'order' => 0]],
    ]);

    // O arquivo é minúsculo em bytes (cabe fácil no orçamento já testado
    // acima) — só a dimensão em pixels bloqueia, nunca o tamanho em disco.
    expect($this->builder->build($doc))->toBeNull();
});

it('[GAP] arquivo com cabeçalho PNG válido mas truncado (não decodifica de verdade) nunca entra no laudo — achado na 11ª rodada de revisão adversarial Codex', function () {
    $truncated = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/truncado.png',
    ]);
    Storage::disk('s3')->put('exams/truncado.png', reportImageTruncatedPng());

    // getimagesizefromstring() sozinho aceitaria isto (cabeçalho válido) —
    // prova que é a checagem de decode de verdade que bloqueia, não o
    // teto de dimensão (já cobdo no teste da bomba de descompressão acima).
    expect(getimagesizefromstring(reportImageTruncatedPng()))->not->toBeFalse();

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'images' => [['exam_id' => $truncated->id, 'order' => 0]],
    ]);

    expect($this->builder->build($doc))->toBeNull();
});

it('[GAP] imagem decodificável mas que excederia o orçamento de memória (RasterMemoryBudget) nunca entra no laudo — mesma proteção de GenerateExamDerivatives::imageFromRaster(), achado na verificação adversarial da 11ª rodada', function () {
    $exam = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/normal.jpg',
    ]);
    Storage::disk('s3')->put('exams/normal.jpg', reportImageFakeJpeg());

    // Orçamento zerado força a rejeição independente do tamanho real do
    // arquivo — decodificar até 40 megapixels dentro da MESMA requisição
    // HTTP de emissão (não um job em fila) pode faltar memória de verdade,
    // e o fatal error de imagecreatefromstring() por falta de memória não
    // é capturável (quebraria a requisição inteira, não só "esta imagem
    // não entra").
    $budget = Mockery::mock(RasterMemoryBudget::class)->makePartial();
    $budget->shouldReceive('available')->once()->andReturn(0);
    app()->instance(RasterMemoryBudget::class, $budget);

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'images' => [['exam_id' => $exam->id, 'order' => 0]],
    ]);

    expect($this->builder->build($doc))->toBeNull();
});

it('pareia OD/OE por grupo (sessão mesclada), preservando ordem do 1º grupo visto', function () {
    // Grupo B (data+equipamento nulo+tipo) — mesmo exam_id (tipo) + mesma
    // data basta pra formar a chave de grupo; aparece PRIMEIRO na lista.
    $examType  = ExamType::factory()->create()->id;
    $sessionId = (string) Str::uuid();
    $bOe       = PatientExam::factory()->create([
        'patient_id'        => $this->patient->id, 'display_archive' => 'exams/b_oe.jpg',
        'laterality'        => 2, 'exam_id' => $examType,
        'exam_performed_at' => '2026-02-12 10:00:00',
    ]);
    // Grupo A (exam_session_id mesclado) aparece DEPOIS.
    $aOe = PatientExam::factory()->create([
        'patient_id' => $this->patient->id, 'display_archive' => 'exams/a_oe.jpg',
        'laterality' => 2, 'exam_session_id' => $sessionId,
    ]);
    $bOd = PatientExam::factory()->create([
        'patient_id'        => $this->patient->id, 'display_archive' => 'exams/b_od.jpg',
        'laterality'        => 1, 'exam_id' => $examType,
        'exam_performed_at' => '2026-02-12 10:00:00',
    ]);
    $aOd = PatientExam::factory()->create([
        'patient_id' => $this->patient->id, 'display_archive' => 'exams/a_od.jpg',
        'laterality' => 1, 'exam_session_id' => $sessionId,
    ]);

    foreach (['exams/b_oe.jpg', 'exams/a_oe.jpg', 'exams/b_od.jpg', 'exams/a_od.jpg'] as $path) {
        Storage::disk('s3')->put($path, reportImageFakeJpeg());
    }

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'pair_od_oe' => true,
        'images'  => [
            ['exam_id' => $bOe->id, 'caption' => 'b-oe', 'order' => 0],
            ['exam_id' => $aOe->id, 'caption' => 'a-oe', 'order' => 1],
            ['exam_id' => $bOd->id, 'caption' => 'b-od', 'order' => 2],
            ['exam_id' => $aOd->id, 'caption' => 'a-od', 'order' => 3],
        ],
    ]);

    $captions = array_column($this->builder->build($doc)['images'], 'caption');
    expect($captions)->toBe(['b-od', 'b-oe', 'a-od', 'a-oe']);
});

it('sem pair_od_oe, mantém a ordem gravada (não reagrupa)', function () {
    $e1 = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'display_archive' => 'exams/e1.jpg', 'laterality' => 2]);
    $e2 = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'display_archive' => 'exams/e2.jpg', 'laterality' => 1]);
    Storage::disk('s3')->put('exams/e1.jpg', reportImageFakeJpeg());
    Storage::disk('s3')->put('exams/e2.jpg', reportImageFakeJpeg());

    $doc = docWithImageBlock($this, [
        'enabled' => true, 'pair_od_oe' => false,
        'images'  => [
            ['exam_id' => $e1->id, 'caption' => 'primeiro-oe', 'order' => 0],
            ['exam_id' => $e2->id, 'caption' => 'segundo-od', 'order' => 1],
        ],
    ]);

    $captions = array_column($this->builder->build($doc)['images'], 'caption');
    expect($captions)->toBe(['primeiro-oe', 'segundo-od']);
});

it('[GAP] teto de bytes acumulados (max_embedded_bytes) impede baixar uma imagem que não cabe no orçamento restante', function () {
    // Orçamento BEM BAIXO: só 10 bytes sobram depois do 1º exame — o 2º
    // exame tem um tamanho "pequeno" em termos absolutos, mas ainda maior
    // que o que resta. size() é checado ANTES de get() (achado via revisão
    // Codex: 50 imagens × 10 MB esgotava os 512 MB de memory_limit) — este
    // teste prova que o 2º candidato nunca chega a ser baixado.
    $rawBig  = reportImageFakeJpeg();
    $sizeBig = strlen($rawBig);
    $budget  = $sizeBig + 10;
    config()->set('eye_image_capture.image_report.max_embedded_bytes', $budget);

    $examBig = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/big.jpg',
    ]);
    $examSmall = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/small.jpg',
    ]);

    $diskDouble = Mockery::mock();
    $diskDouble->shouldReceive('exists')->andReturnUsing(
        fn ($path) => in_array($path, ['exams/big.jpg', 'exams/small.jpg'], true),
    );
    $diskDouble->shouldReceive('size')->andReturnUsing(
        // "Grande" pro 1º (cabe no orçamento do início); "pequeno" pro 2º em
        // termos absolutos, mas maior que os 10 bytes que sobram depois do 1º.
        fn ($path) => $path === 'exams/big.jpg' ? $sizeBig : 50,
    );
    // 'exams/small.jpg' nunca é stubado no get(): se o código chegasse a
    // chamá-lo, o Mockery já quebraria o teste por falta de handler.
    $diskDouble->shouldReceive('get')->with('exams/big.jpg')->andReturn($rawBig);
    Storage::shouldReceive('disk')->with('s3')->andReturn($diskDouble);

    $doc = docWithImageBlock($this, [
        'enabled' => true,
        'images'  => [
            ['exam_id' => $examBig->id, 'caption' => 'grande', 'order' => 0],
            ['exam_id' => $examSmall->id, 'caption' => 'pequeno', 'order' => 1],
        ],
    ]);

    $result = $this->builder->build($doc);

    expect($result)->not->toBeNull()
        ->and($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['caption'])->toBe('grande')
        ->and($result['images'][0]['data_uri'])->toStartWith('data:image/jpeg;base64,');
});

it('[GAP] orçamento zerado não chega a chamar o storage pro próximo candidato (guard de entrada)', function () {
    // Mesmo orçamento do teste anterior, mas aqui o 1º exame consome o
    // orçamento até EXATAMENTE zero (não só "menos que o 2º precisa") — prova
    // que o guard `if ($remainingBudget <= 0) return null` no topo de
    // resolveDataUri() dispensa a chamada de storage por completo pro 2º
    // candidato, em vez de só descartar depois de checar exists()/size().
    // 'exams/small2.jpg' não tem handler nenhum (nem exists()): se o código
    // chegasse a consultar o storage pra ele, o Mockery já quebraria o teste
    // por falta de handler, igual ao padrão já usado acima com get().
    $rawBig  = reportImageFakeJpeg();
    $sizeBig = strlen($rawBig);
    config()->set('eye_image_capture.image_report.max_embedded_bytes', $sizeBig);

    $examBig = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/big2.jpg',
    ]);
    $examSmall = PatientExam::factory()->create([
        'patient_id'      => $this->patient->id,
        'display_archive' => 'exams/small2.jpg',
    ]);

    $diskDouble = Mockery::mock();
    $diskDouble->shouldReceive('exists')->with('exams/big2.jpg')->andReturn(true);
    $diskDouble->shouldReceive('size')->with('exams/big2.jpg')->andReturn($sizeBig);
    $diskDouble->shouldReceive('get')->with('exams/big2.jpg')->andReturn($rawBig);
    Storage::shouldReceive('disk')->with('s3')->andReturn($diskDouble);

    $doc = docWithImageBlock($this, [
        'enabled' => true,
        'images'  => [
            ['exam_id' => $examBig->id, 'caption' => 'grande', 'order' => 0],
            ['exam_id' => $examSmall->id, 'caption' => 'pequeno', 'order' => 1],
        ],
    ]);

    $result = $this->builder->build($doc);

    expect($result)->not->toBeNull()
        ->and($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['caption'])->toBe('grande');
});
