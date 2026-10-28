<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Covenant, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use App\Services\Lgpd\PatientDataExporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PatientDataExporter::medicalRecords() (usado por LgpdExportController::
 * export(), autoatendimento 100% síncrono e sem fila) nunca pode duplicar
 * o data_uri do laudo de imagem embutida (até ~27 MiB em base64 por laudo
 * — ReportImageBlockBuilder/ExamManagerReportController::store()) no export:
 * um histórico com poucos laudos de imagem já esgotava o memory_limit
 * construindo o array completo e depois fazendo json_encode de novo
 * (achado via revisão adversarial Codex). Mesma redação já usada em
 * MedicalRecordDocumentation::getAuditableAttributes() pra audit_logs —
 * aqui reaproveitada na exportação, que já trata foto/vídeo de exame como
 * metadado (campo has_file, nunca os bytes — ver exams()).
 */
it('[GAP] exportação LGPD nunca duplica o data_uri de laudos com imagem embutida, mesmo com 10 laudos pesados no histórico', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $person  = People::factory()->create();
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => $person->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
    $doctor = doctorRecordForEntityUser(createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value));
    $record = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    $dataUri = 'data:image/jpeg;base64,' . str_repeat('A', 50000);

    for ($i = 0; $i < 10; $i++) {
        MedicalRecordDocumentation::create([
            'medical_record_id' => $record->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $doctor->id,
            'type'              => DocumentationType::Report->value,
            'title'             => "Laudo com imagem embutida #{$i}",
            'content'           => '<p>Achados.</p>',
            'metadata'          => [
                'clinical_indication' => 'Suspeita de glaucoma',
                'image_report'        => [
                    'enabled' => true, 'columns' => 2,
                    'images'  => [['data_uri' => $dataUri, 'caption' => 'OD']],
                ],
            ],
        ]);
    }

    $export         = app(PatientDataExporter::class)->export($patient);
    $documentations = $export['medical_records'][0]['documentations'];

    expect($documentations)->toHaveCount(10);

    foreach ($documentations as $doc) {
        $image = $doc['details']['image_report']['images'][0];

        // Resto do metadata continua intacto — a correção nunca pode
        // esconder informação clínica real, só o peso dos bytes da imagem.
        expect($doc['details']['clinical_indication'])->toBe('Suspeita de glaucoma')
            ->and($image['caption'])->toBe('OD')
            ->and($image['data_uri'])->toContain((string) strlen($dataUri))
            ->and($image['data_uri'])->toContain(hash('sha256', $dataUri));
    }

    expect(json_encode($export))->not->toContain($dataUri);
});

/**
 * [GAP] No teto REAL de bytes embutidos por laudo
 * (eye_image_capture.image_report.max_embedded_bytes, 20 MiB), exportar
 * nunca pode reter o metadata cru de todos os laudos ao mesmo tempo antes
 * de redigir — medicalRecords() usava get()->groupBy(), que materializa
 * TODAS as linhas (e os ~20 MiB decodificados de cada) simultaneamente
 * ANTES de qualquer redação rodar; com poucos laudos no teto real isso já
 * passava de 512 MiB (achado via revisão adversarial Codex, reproduzido
 * isoladamente carregando os models). Agora lê e redige uma documentação por
 * vez, nunca retém o cru de mais de uma. Isto mede só a memória do PHP — a
 * memória NATIVA do PDO_PGSQL (que bufferiza o resultado inteiro de cada
 * consulta) tem o teste de RSS em tests/Integration/PatientDataExportMemoryTest
 * e a regra de "uma linha por consulta" é conferida mais abaixo, pelo SQL.
 */
it('[GAP] exportação LGPD no teto real de 20 MiB por laudo não escala memória com o histórico — redige uma documentação por vez', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $person  = People::factory()->create();
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => $person->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
    $doctor = doctorRecordForEntityUser(createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value));
    $record = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    $reportSize  = (int) config('eye_image_capture.image_report.max_embedded_bytes');
    $reportCount = 5;

    for ($i = 0; $i < $reportCount; $i++) {
        MedicalRecordDocumentation::create([
            'medical_record_id' => $record->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $doctor->id,
            'type'              => DocumentationType::Report->value,
            'title'             => "Laudo pesado #{$i}",
            'content'           => '<p>Achados.</p>',
            'metadata'          => [
                'image_report' => [
                    'enabled' => true, 'columns' => 2,
                    'images'  => [['data_uri' => 'data:image/jpeg;base64,' . str_repeat('A', $reportSize), 'caption' => 'OD']],
                ],
            ],
        ]);
    }

    gc_collect_cycles();
    $peakBeforeExport = memory_get_peak_usage();

    $export = app(PatientDataExporter::class)->export($patient);

    $peakDelta = memory_get_peak_usage() - $peakBeforeExport;

    expect($export['medical_records'][0]['documentations'])->toHaveCount($reportCount);
    // 5 laudos de 20 MiB cada = 100 MiB cru. get()->groupBy() (bug
    // original) retinha TODOS decodificados ao mesmo tempo antes de
    // redigir — delta na faixa de 100 MiB. Uma por vez nunca retém mais
    // que ~1: o delta desta chamada fica bem abaixo até de UM laudo.
    expect($peakDelta)->toBeLessThan($reportSize * 2);
});

/**
 * [GAP] A referência inline <img data-exam-image-id> (botão "Inserir
 * imagem", distinta do bloco acima) tem seu byte congelado em
 * metadata.inline_images — mas documentationData() usava
 * contentForRender() pro campo 'content' do export, que MESCLA esse byte
 * de volta no HTML antes de qualquer redação rodar. metadata.details saía
 * redigido (hash/tamanho), mas 'content' saía com o data_uri CRU inteiro
 * — reabrindo o mesmo risco de memória corrigido 2 rodadas atrás, só por
 * outro campo (achado via revisão adversarial Codex). Agora usa
 * contentWithoutDateHeader() (nunca injeta bytes) — content mantém só a
 * referência, igual ao resto do metadata redigido.
 */
it('[GAP] exportação LGPD nunca injeta o byte da imagem inline de volta em content — só a referência, igual ao metadata redigido', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $person  = People::factory()->create();
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => $person->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
    $doctor = doctorRecordForEntityUser(createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value));
    $record = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    $dataUri = 'data:image/jpeg;base64,' . str_repeat('A', 50000);
    $examId  = (string) Str::uuid();

    for ($i = 0; $i < 10; $i++) {
        MedicalRecordDocumentation::create([
            'medical_record_id' => $record->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $doctor->id,
            'type'              => DocumentationType::Report->value,
            'title'             => "Laudo com imagem inline #{$i}",
            'content'           => '<p>Achados.</p><p><img data-exam-image-id="' . $examId . '" alt="OD"></p>',
            'metadata'          => ['inline_images' => [$examId => $dataUri]],
        ]);
    }

    $export         = app(PatientDataExporter::class)->export($patient);
    $documentations = $export['medical_records'][0]['documentations'];

    expect($documentations)->toHaveCount(10);

    foreach ($documentations as $doc) {
        // content mantém a referência (nunca injeta o byte de volta).
        expect($doc['content'])->toContain('data-exam-image-id="' . $examId . '"')
            ->and($doc['content'])->not->toContain($dataUri)
            // metadata.inline_images continua redigido (hash/tamanho),
            // mesma regra já provada pro bloco de imagens.
            ->and($doc['details']['inline_images'][$examId])->toContain((string) strlen($dataUri))
            ->and($doc['details']['inline_images'][$examId])->toContain(hash('sha256', $dataUri));
    }

    expect(json_encode($export))->not->toContain($dataUri);
});

/** Paciente + prontuário + médico de uma clínica nova (setup dos testes de SQL/ordem abaixo). */
function lgpdemSetup(): array
{
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
    $doctor = doctorRecordForEntityUser(createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value));

    return [$entity, $patient, $doctor];
}

function lgpdemDocumentation(Patient $patient, MedicalRecord $record, $doctor, string $title, ?string $createdAt = null): MedicalRecordDocumentation
{
    $documentation = MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $patient->id,
        'doctor_id'         => $doctor->id,
        'type'              => DocumentationType::Report->value,
        'title'             => $title,
        'content'           => '<p>Achados.</p>',
        'metadata'          => ['clinical_indication' => $title],
    ]);

    if ($createdAt !== null) {
        DB::table('medical_record_documentations')->where('id', $documentation->id)->update(['created_at' => $createdAt]);
    }

    return $documentation;
}

/**
 * [GAP] O PDO_PGSQL (libpq) lê o resultado INTEIRO de cada consulta pra memória
 * nativa do processo no execute() — cursor() só entrega as linhas uma a uma
 * depois disso (achado via revisão adversarial Codex; o pico real medido está
 * em tests/Integration/PatientDataExportMemoryTest). A regra que impede o
 * histórico inteiro de ficar residente é: nenhuma consulta traz o metadata de
 * mais de UMA documentação. Conferida pelo SQL, que é determinístico.
 */
it('[GAP] exportação LGPD lê o conteúdo das documentações UMA por consulta (chave primária) — nenhum SELECT traz o metadata de várias linhas', function () {
    [$entity, $patient, $doctor] = lgpdemSetup();
    $record                      = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    foreach (['A', 'B', 'C', 'D'] as $title) {
        lgpdemDocumentation($patient, $record, $doctor, "Laudo {$title}");
    }

    $log = [];
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = strtolower($query->sql);
    });

    $export = app(PatientDataExporter::class)->export($patient);

    expect($export['medical_records'][0]['documentations'])->toHaveCount(4);

    $documentationReads = array_values(array_filter(
        $log,
        fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from "medical_record_documentations"'),
    ));

    // 1 consulta leve (só id + prontuário) + 1 leitura por chave primária de cada uma das 4.
    expect($documentationReads)->toHaveCount(5);

    $light = 0;

    foreach ($documentationReads as $sql) {
        if (str_starts_with($sql, 'select "id", "medical_record_id" from')) {
            $light++;

            continue;
        }

        // Leitura que traz o conteúdo: tem que ser de UMA linha.
        expect($sql)->toContain('"id" = ?')->and($sql)->toContain('limit 1');
    }

    expect($light)->toBe(1);
});

it('exportação LGPD mantém as documentações em ordem cronológica dentro de cada prontuário', function () {
    [$entity, $patient, $doctor] = lgpdemSetup();
    $first                       = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
    $second                      = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    // Inseridas fora de ordem de propósito; created_at manda.
    lgpdemDocumentation($patient, $first, $doctor, 'Primeiro prontuário — terceiro', '2026-03-03 10:00:00');
    lgpdemDocumentation($patient, $second, $doctor, 'Segundo prontuário — segundo', '2026-04-02 10:00:00');
    lgpdemDocumentation($patient, $first, $doctor, 'Primeiro prontuário — primeiro', '2026-03-01 10:00:00');
    lgpdemDocumentation($patient, $second, $doctor, 'Segundo prontuário — primeiro', '2026-04-01 10:00:00');
    lgpdemDocumentation($patient, $first, $doctor, 'Primeiro prontuário — segundo', '2026-03-02 10:00:00');

    $titlesByCode = collect(app(PatientDataExporter::class)->export($patient)['medical_records'])
        ->mapWithKeys(fn (array $r) => [$r['code'] => collect($r['documentations'])->pluck('title')->all()]);

    expect($titlesByCode[$first->fresh()->code])->toBe([
        'Primeiro prontuário — primeiro', 'Primeiro prontuário — segundo', 'Primeiro prontuário — terceiro',
    ])->and($titlesByCode[$second->fresh()->code])->toBe([
        'Segundo prontuário — primeiro', 'Segundo prontuário — segundo',
    ]);
});

it('documentação removida entre a lista de ids e a leitura da linha é ignorada, sem erro na exportação', function () {
    [$entity, $patient, $doctor] = lgpdemSetup();
    $record                      = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
    $keep                        = lgpdemDocumentation($patient, $record, $doctor, 'Fica', '2026-03-01 10:00:00');
    $vanishing                   = lgpdemDocumentation($patient, $record, $doctor, 'Some no meio', '2026-03-02 10:00:00');

    // Logo depois da consulta leve de ids, a outra sessão exclui (soft delete) uma delas.
    $removed = false;
    DB::listen(function (QueryExecuted $query) use (&$removed, $vanishing): void {
        if (! $removed && str_starts_with(strtolower($query->sql), 'select "id", "medical_record_id" from')) {
            $removed = true;
            DB::table('medical_record_documentations')->where('id', $vanishing->id)->update(['deleted_at' => now()]);
        }
    });

    $documentations = app(PatientDataExporter::class)->export($patient)['medical_records'][0]['documentations'];

    expect($removed)->toBeTrue()
        ->and(collect($documentations)->pluck('title')->all())->toBe(['Fica'])
        ->and($keep->fresh()->title)->toBe('Fica');
});
