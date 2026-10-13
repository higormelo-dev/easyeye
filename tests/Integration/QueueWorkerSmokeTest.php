<?php

declare(strict_types=1);

/**
 * Alinhamento filas/timeout/retry/parada do worker Supervisor — achado via
 * auditoria de encerramento, EE-09. tiss-xml/tiss-send/tiss-returns
 * (config/tiss.php) nunca apareciam no --queue do worker
 * (.docker/supervisor/easyeye-worker.conf) — jobs despachados pra elas
 * ficavam parados pra sempre na tabela `jobs`. QUEUE_CONNECTION=sync do
 * phpunit.xml (dispatch roda na hora, nunca fila de verdade) não prova
 * isso — por isso um worker REAL, processo próprio, igual ao comando do
 * Supervisor em produção (RELATORIO.md: "Testes com QUEUE_CONNECTION=sync
 * não bastam").
 */

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Integration\Support\Ee09MarkerJob;
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

it('[GAP] worker real (comando do Supervisor) drena as 3 filas TISS — não ficam paradas pra sempre (EE-09)', function () {
    $conf = file_get_contents(base_path('.docker/supervisor/easyeye-worker.conf'));
    preg_match('/--queue=(\S+)/', (string) $conf, $matches);
    $queueList = $matches[1] ?? '';

    expect($queueList)->toContain('tiss-xml')
        ->and($queueList)->toContain('tiss-send')
        ->and($queueList)->toContain('tiss-returns');

    $dir = sys_get_temp_dir() . '/ee09-' . Str::uuid();
    mkdir($dir, 0700);

    $queues = ['tiss-xml', 'tiss-send', 'tiss-returns'];

    foreach ($queues as $queue) {
        Ee09MarkerJob::dispatch("{$dir}/{$queue}.marker")
            ->onConnection('database')
            ->onQueue($queue);
    }

    $process = Process::path(base_path())
        ->env(ee09WorkerEnv())
        ->timeout(30)
        ->run([
            PHP_BINARY, 'artisan', 'queue:work', 'database',
            '--queue=' . $queueList,
            '--stop-when-empty',
            '--timeout=10',
        ]);

    expect($process->successful())
        ->toBeTrue("worker saiu com erro — stdout: {$process->output()} | stderr: {$process->errorOutput()}");

    foreach ($queues as $queue) {
        expect(is_file("{$dir}/{$queue}.marker"))->toBeTrue("fila '{$queue}' não foi drenada pelo worker real");
    }
});

// redis: o .env.example seleciona QUEUE_CONNECTION=redis e o default dele
// ficou em 90 s depois do EE-09 corrigir só o `database` (F3, revisão
// adversarial Codex). O comportamento real no Redis está em
// QueueRedisReservationTest.php.
it('retry_after da conexão de fila é maior que o maior $timeout declarado entre os jobs (EE-09)', function (string $connection) {
    expect((int) config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan(maxDeclaredJobTimeout());
})->with(['database', 'redis']);

it('stopwaitsecs do worker Supervisor é maior que o maior $timeout declarado entre os jobs da fila (EE-09)', function () {
    $conf = file_get_contents(base_path('.docker/supervisor/easyeye-worker.conf'));
    preg_match('/^stopwaitsecs=(\d+)/m', (string) $conf, $matches);

    expect($matches)->not->toBeEmpty('stopwaitsecs não declarado em easyeye-worker.conf');

    $stopwaitsecs = (int) $matches[1];

    expect($stopwaitsecs)->toBeGreaterThan(maxDeclaredJobTimeout());
});

/**
 * Maior $timeout declarado (propriedade literal, não calculado no
 * construtor) entre todas as classes de job da aplicação.
 */
function maxDeclaredJobTimeout(): int
{
    $maxTimeout = 60; // default implícito do Laravel pra job sem $timeout próprio

    foreach (allQueueableJobClasses() as $class) {
        $ref = new ReflectionClass($class);

        if (! $ref->hasProperty('timeout')) {
            continue;
        }

        $instance = $ref->newInstanceWithoutConstructor();
        $property = $ref->getProperty('timeout');

        // Job que calcula o timeout no construtor (ex.: RunAiWorkflowJob, a
        // partir de config) não tem valor acessível sem rodar o construtor
        // de verdade — fora do alcance desta checagem estática.
        if (! $property->isInitialized($instance)) {
            continue;
        }

        $timeout = $property->getValue($instance);

        if (is_int($timeout) && $timeout > $maxTimeout) {
            $maxTimeout = $timeout;
        }
    }

    return $maxTimeout;
}

/**
 * @return list<class-string>
 */
function allQueueableJobClasses(): array
{
    $classes = [];
    $root    = app_path();

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class    = 'App\\' . str_replace('/', '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $ref = new ReflectionClass($class);

        if ($ref->isAbstract() || $ref->isInterface() || ! $ref->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        $classes[] = $class;
    }

    return $classes;
}
