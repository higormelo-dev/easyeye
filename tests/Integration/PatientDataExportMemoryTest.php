<?php

declare(strict_types=1);

/**
 * Exportação LGPD (autoatendimento, síncrona) com laudos de imagem embutida
 * pesados, medindo o RSS do PROCESSO no PostgreSQL de verdade — achado via
 * revisão adversarial Codex: PatientDataExporter::medicalRecords() trocou
 * get()->groupBy() por cursor(), mas o PDO_PGSQL (libpq) lê o resultado
 * INTEIRO da consulta pra memória nativa no execute(); cursor() só entrega as
 * linhas uma a uma DEPOIS disso. Com o metadata de cada laudo chegando a ~27
 * MiB em base64, o histórico inteiro ficava residente antes de qualquer
 * redação — fora do alcance de memory_get_peak_usage() e do memory_limit do
 * PHP (o teste de pico do PHP em tests/Feature/PatientPortal/
 * LgpdExportMemoryTest passava mesmo assim) e dentro do limite do contêiner.
 *
 * O export roda num PROCESSO filho (tests/Integration/Support/
 * lgpd_export_child.php) pra medir o pico de RSS de uma exportação isolada,
 * sem a memória acumulada de outros testes. Fica em tests/Integration
 * (DatabaseMigrations; precisa de PostgreSQL, que é quem tem o comportamento) e
 * roda por caminho explícito.
 */

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function pdemChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

it('[GAP] exportação LGPD no PostgreSQL não deixa o metadata de todos os laudos pesados residente ao mesmo tempo — pico de RSS fica na ordem de UM laudo (achado via revisão adversarial Codex)', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
    $entityUser = createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value);
    $doctor     = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);
    $record = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    $reports     = 16;
    $reportBytes = 8 * 1024 * 1024; // 16 × 8 MiB = 128 MiB de metadata no histórico
    $dataUri     = 'data:image/jpeg;base64,' . str_repeat('A', $reportBytes);

    for ($i = 0; $i < $reports; $i++) {
        MedicalRecordDocumentation::create([
            'medical_record_id' => $record->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $doctor->id,
            'type'              => DocumentationType::Report->value,
            'title'             => "Laudo pesado #{$i}",
            'content'           => '<p>Achados.</p>',
            'metadata'          => ['image_report' => ['enabled' => true, 'columns' => 2, 'images' => [['data_uri' => $dataUri, 'caption' => 'OD']]]],
        ]);
    }

    unset($dataUri);

    $result = Process::path(base_path())
        ->env(pdemChildEnv())
        ->timeout(180)
        ->run([PHP_BINARY, 'tests/Integration/Support/lgpd_export_child.php', (string) $patient->id]);

    expect($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}");

    $measured = json_decode($result->output(), true);

    if (! $measured['reset']) {
        $this->markTestSkipped('O kernel não deixou zerar o pico de RSS (/proc/self/clear_refs).');
    }

    expect($measured['documentations'])->toBe($reports);

    $deltaMiB = ($measured['peak_kb'] - $measured['before_rss_kb']) / 1024;
    $totalMiB = $reports * $reportBytes / 1024 / 1024;

    // Lendo tudo de uma vez (cursor() no PDO_PGSQL) o pico passa de 100 MiB
    // acima do RSS de partida — o metadata dos 16 laudos fica residente junto.
    // Uma linha por vez, o pico fica na faixa de poucos laudos (~3 cópias do
    // maior: nativa + string do PHP + decodificada). Limite: metade do total.
    expect($deltaMiB)->toBeLessThan($totalMiB / 2, sprintf(
        'pico de RSS subiu %.0f MiB com %.0f MiB de metadata no histórico (partida=%d KiB, pico=%d KiB)',
        $deltaMiB,
        $totalMiB,
        $measured['before_rss_kb'],
        $measured['peak_kb'],
    ));
});
