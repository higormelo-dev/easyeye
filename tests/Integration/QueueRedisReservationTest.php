<?php

declare(strict_types=1);

/**
 * Reserva REAL no Redis — achado via revisão adversarial Codex (F3): o
 * `retry_after` da conexão `redis` ficou em 90 s enquanto os jobs de
 * importação declaram $timeout de 900 s e o Supervisor sobe 2 consumidores.
 * O teste de config (QueueWorkerSmokeTest) prova o VALOR; este prova o
 * COMPORTAMENTO: um worker de verdade (processo separado, `queue:work
 * redis`) pega um job de $timeout 900 s e a reserva no Redis (ZSET
 * queues:{fila}:reserved, score = agora + retry_after) tem que durar mais
 * que o $timeout — senão outro consumidor reentrega o MESMO job no meio.
 *
 * Fila com nome único e chaves apagadas no fim: não encosta nas filas da
 * aplicação. Roda só com caminho explícito (tests/Integration fica fora das
 * suítes do phpunit.xml) e pula sozinho se o Redis não estiver acessível.
 */

use Illuminate\Support\Facades\{Process, Redis};
use Illuminate\Support\Str;
use Tests\Integration\Support\Ee09SlowMarkerJob;
use Tests\TestCase;

uses(TestCase::class);

it('worker real no Redis reserva o job por mais tempo que o maior $timeout — nenhum outro consumidor reentrega o mesmo job (F3)', function () {
    try {
        $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $redis->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis inacessível: ' . $e->getMessage());
    }

    $queue    = 'ee-f3-' . Str::uuid();
    $reserved = "queues:{$queue}:reserved";
    $dir      = sys_get_temp_dir() . '/ee-f3-' . Str::uuid();
    mkdir($dir, 0700);

    $worker = null;

    try {
        Ee09SlowMarkerJob::dispatch($dir)->onConnection('redis')->onQueue($queue);

        $worker = Process::path(base_path())
            ->env(['APP_ENV' => 'testing'])
            ->timeout(120)
            ->start([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=' . $queue, '--once']);

        // O job começou a rodar no worker filho = já foi reservado.
        $deadline = microtime(true) + 30;

        while (! is_file("{$dir}/started") && microtime(true) < $deadline) {
            if (! $worker->running()) {
                break;
            }

            usleep(100_000);
        }

        expect(is_file("{$dir}/started"))->toBeTrue(
            "o worker real não chegou a rodar o job — stdout: {$worker->output()} | stderr: {$worker->errorOutput()}",
        );

        $members = $redis->zrange($reserved, 0, -1);

        expect($members)->toHaveCount(1);

        $remaining       = (float) $redis->zscore($reserved, $members[0]) - time();
        $retryAfter      = (int) config('queue.connections.redis.retry_after');
        $declaredTimeout = (new Ee09SlowMarkerJob($dir))->timeout;

        // Vale mais que o $timeout do job: nenhum outro worker pega o mesmo
        // job enquanto o 1º, legítimo, ainda tem tempo de rodar...
        expect($remaining)->toBeGreaterThan($declaredTimeout)
            // ...e é o retry_after configurado de verdade (folga pro boot do worker).
            ->and($remaining)->toBeGreaterThan($retryAfter - 30)
            ->and($remaining)->toBeLessThanOrEqual($retryAfter + 5);

        file_put_contents("{$dir}/release", '1');
        $result = $worker->wait();

        expect($result->successful())->toBeTrue("worker saiu com erro — stdout: {$result->output()} | stderr: {$result->errorOutput()}")
            ->and(is_file("{$dir}/done"))->toBeTrue()
            // Job concluído = reserva removida (ack), sem sobra pra reentrega.
            ->and((int) $redis->zcard($reserved))->toBe(0);
    } finally {
        if ($worker !== null && $worker->running()) {
            $worker->stop(2);
        }

        foreach (['', ':reserved', ':delayed', ':notify'] as $suffix) {
            $redis->del("queues:{$queue}{$suffix}");
        }

        foreach (['started', 'release', 'done'] as $file) {
            @unlink("{$dir}/{$file}");
        }

        @rmdir($dir);
    }
});
