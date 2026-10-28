<?php

declare(strict_types=1);

/**
 * Worker REAL (processo próprio, igual ao comando do Supervisor) — achado via auditoria de encerramento, EE-09:
 * tiss-xml/tiss-send/tiss-returns (config/tiss.php) nunca apareciam no --queue do worker
 * (.docker/supervisor/easyeye-worker.conf) e os jobs despachados pra elas ficavam parados pra sempre na tabela `jobs`.
 * QUEUE_CONNECTION=sync do phpunit.xml (dispatch roda na hora, nunca fila de verdade) não prova isso (RELATORIO.md:
 * "Testes com QUEUE_CONNECTION=sync não bastam").
 *
 * Só o que precisa de worker/banco de verdade mora aqui (DatabaseMigrations recria o schema; tests/Integration fica
 * fora das suítes do phpunit.xml). As travas ESTÁTICAS da topologia (parse do Supervisor, do entrypoint, do crontab e
 * da config; retry_after, --timeout, stopwaitsecs, filas configuradas) rodam na CI em
 * tests/Feature/Queue/WorkerTopologyTest.php. Reserva real no Redis: QueueRedisReservationTest.php.
 */

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Integration\Support\Ee09MarkerJob;
use Tests\Support\QueueTopology;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/**
 * Ambiente do worker FILHO precisa bater com o do processo de teste ATUAL
 * (mesmo banco easyeye_test) — um subprocesso novo não herda a conexão já
 * aberta, só as variáveis de ambiente que passarmos explicitamente.
 */
function ee09WorkerEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'       => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST'       => (string) ($db['host'] ?? ''),
        'DB_PORT'       => (string) ($db['port'] ?? ''),
        'DB_DATABASE'   => (string) ($db['database'] ?? ''),
        'DB_USERNAME'   => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'   => (string) ($db['password'] ?? ''),
    ];
}

it('[GAP] worker real (comando de cada programa fast) drena critical, default e as 3 filas TISS — não ficam paradas pra sempre (EE-09)', function (string $program) {
    $queueList = QueueTopology::programs()[$program]['queues'];

    expect($queueList)->toContain('critical')
        ->and($queueList)->toContain('default')
        ->and($queueList)->toContain('tiss-xml')
        ->and($queueList)->toContain('tiss-send')
        ->and($queueList)->toContain('tiss-returns');

    $dir = sys_get_temp_dir() . '/ee09-' . Str::uuid();
    mkdir($dir, 0700);

    foreach ($queueList as $queue) {
        Ee09MarkerJob::dispatch("{$dir}/{$queue}.marker")
            ->onConnection('database')
            ->onQueue($queue);
    }

    $process = Process::path(base_path())
        ->env(ee09WorkerEnv())
        ->timeout(30)
        ->run([
            PHP_BINARY, 'artisan', 'queue:work', 'database',
            '--queue=' . implode(',', $queueList),
            '--stop-when-empty',
            '--timeout=10',
        ]);

    expect($process->successful())
        ->toBeTrue("worker saiu com erro — stdout: {$process->output()} | stderr: {$process->errorOutput()}");

    foreach ($queueList as $queue) {
        expect(is_file("{$dir}/{$queue}.marker"))->toBeTrue("fila '{$queue}' não foi drenada pelo worker real");
    }
})->with([
    'fast-critical' => ['easyeye-worker-fast-critical'],
    'fast-tiss'     => ['easyeye-worker-fast-tiss'],
]);

it('o worker respeita a ordem do --queue de cada programa fast: a fila da frente sai antes, mesmo com o job da outra chegando primeiro (prioridade viva)', function (string $program, string $first, string $second) {
    $queueList = QueueTopology::programs()[$program]['queues'];

    expect(array_search($first, $queueList, true))->toBeLessThan((int) array_search($second, $queueList, true));

    $dir = sys_get_temp_dir() . '/ee09-' . Str::uuid();
    mkdir($dir, 0700);

    // O job da fila de trás chega ANTES; só a ordem do --queue pode fazer o da frente passar na vez.
    Ee09MarkerJob::dispatch("{$dir}/{$second}.marker")->onConnection('database')->onQueue($second);
    Ee09MarkerJob::dispatch("{$dir}/{$first}.marker")->onConnection('database')->onQueue($first);

    $process = Process::path(base_path())
        ->env(ee09WorkerEnv())
        ->timeout(30)
        ->run([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=' . implode(',', $queueList), '--stop-when-empty', '--timeout=10']);

    expect($process->successful())
        ->toBeTrue("worker saiu com erro — stdout: {$process->output()} | stderr: {$process->errorOutput()}")
        ->and((int) file_get_contents("{$dir}/{$first}.marker"))->toBeLessThan((int) file_get_contents("{$dir}/{$second}.marker"));
})->with([
    'fast-critical: critical antes de default' => ['easyeye-worker-fast-critical', 'critical', 'default'],
    'fast-tiss: tiss-xml antes de default'     => ['easyeye-worker-fast-tiss', 'tiss-xml', 'default'],
    'fast-tiss: tiss-send antes de default'    => ['easyeye-worker-fast-tiss', 'tiss-send', 'default'],
    'fast-tiss: tiss-returns antes de default' => ['easyeye-worker-fast-tiss', 'tiss-returns', 'default'],
    'fast-tiss: default antes de critical'     => ['easyeye-worker-fast-tiss', 'default', 'critical'],
]);
