<?php

declare(strict_types=1);

/**
 * failed() dos jobs do lote TISS × OUTRA sessão que comita a reivindicação do
 * envio no meio — duas conexões PostgreSQL de verdade. Achado via revisão
 * adversarial Codex (19ª rodada): failed() lia o lote SEM trava, decidia pelo
 * retrato lido e escrevia "erro" incondicionalmente. Uma sessão que comitasse
 * "enviando"/"enviado" entre a leitura e a escrita tinha o status sobrescrito: o
 * lote voltava a ser editável e a regeneração saía com XML e chave novos,
 * enquanto a entrega original podia já ter chegado à operadora.
 *
 * Agora a leitura e a escrita ficam na mesma transação, com a linha do lote
 * travada (FOR UPDATE — a trava da reivindicação/fechamento do envio, da geração
 * do XML e das edições do faturamento): failed() espera quem já está na linha e
 * decide pelo status COMITADO por ela.
 *
 * Aqui o filho trava a linha, grava o status e segura alguns segundos antes de
 * comitar; failed() roda no principal nesse intervalo.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito.
 */

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Jobs\{GenerateTissBatchXmlJob, SendTissBatchJob};
use App\Domains\Tiss\Models\{TissBatch, TissOperator};
use App\Models\Entity;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function tfhrChildEnv(): array
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

/** Lote TISS pronto para enviar (XML pronto), comitado. */
function tfhrBatch(): TissBatch
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    return TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-FALHA-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::XmlReady->value,
    ]);
}

/**
 * Roda o failed() do job no principal ENQUANTO o filho segura a trava da linha do
 * lote (com $childStatus gravado e ainda não comitado). Devolve quanto o failed()
 * esperou e o resultado do filho.
 *
 * @param class-string $jobClass
 *
 * @return array{0: float, 1: ProcessResult}
 */
function tfhrRunFailedDuringChild(string $jobClass, TissBatch $batch, string $childStatus): array
{
    $marker = sys_get_temp_dir() . '/tfhr-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(tfhrChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/tiss_batch_lock_child.php', (string) $batch->id, $marker, '2.5', $childStatus]);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '30s'");

        $startedAt = microtime(true);

        (new $jobClass((string) $batch->id, (string) $batch->entity_id))->failed(new RuntimeException('falha definitiva do job'));

        $waited = microtime(true) - $startedAt;

        return [$waited, $child->wait()];
    } finally {
        @unlink($marker);
    }
}

it('[CORRIDA] SendTissBatchJob::failed() com outra sessão comitando a reivindicação/fechamento do envio no meio: não sobrescreve enviando/enviado/cancelado com "erro"', function (string $childStatus, bool $recordsReason) {
    $batch = tfhrBatch();

    [$waited, $childResult] = tfhrRunFailedDuringChild(SendTissBatchJob::class, $batch, $childStatus);

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // Esperou a outra sessão comitar (o filho segura 2,5 s)...
        ->and($waited)->toBeGreaterThan(1.0)
        // ...e decidiu pelo status COMITADO por ela.
        ->and($batch->fresh()->status->value)->toBe($childStatus)
        ->and($batch->fresh()->last_error)->when($recordsReason, fn ($e) => $e->toContain('falha definitiva do job'), fn ($e) => $e->toBeNull());
})->with([
    'enviando (reivindicação comitada): só registra o motivo' => ['sending', true],
    'enviado (fechamento comitado)'                           => ['sent', false],
    'cancelado'                                               => ['cancelled', false],
]);

it('[CORRIDA] GenerateTissBatchXmlJob::failed() com outra sessão comitando a reivindicação/fechamento do envio no meio: não sobrescreve enviando/enviado/cancelado com "erro"', function (string $childStatus) {
    $batch = tfhrBatch();

    [$waited, $childResult] = tfhrRunFailedDuringChild(GenerateTissBatchXmlJob::class, $batch, $childStatus);

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($batch->fresh()->status->value)->toBe($childStatus)
        ->and($batch->fresh()->last_error)->toBeNull();
})->with(['sending', 'sent', 'cancelled']);

it('a outra sessão só segura a trava e não muda nada: o handler espera, relê o status comitado e leva o lote que ainda não saiu a "erro"', function (string $jobClass) {
    $batch = tfhrBatch();

    [$waited, $childResult] = tfhrRunFailedDuringChild($jobClass, $batch, '-');

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and($batch->fresh()->last_error)->toContain('falha definitiva do job');
})->with([SendTissBatchJob::class, GenerateTissBatchXmlJob::class]);
