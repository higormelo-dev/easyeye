<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Job "longo" pro QueueRedisReservationTest: declara o $timeout do maior job de
 * cada worker (fast 180 s, bulk 900 s), mas só segura a reserva até o
 * teste liberar — sincronizado por arquivos, sem sleep fixo. Mesmo motivo do
 * Ee09MarkerJob pra estar em namespace PSR-4: o worker é um processo
 * separado e só resolve a classe pelo autoloader do Composer.
 */
class Ee09SlowMarkerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param int $timeout 900 = o maior job bulk (imports); 180 = o maior job do fast (IA). Vai serializado no
     *                     payload, então o worker real usa ESTE valor (Worker::timeoutForJob), não o do comando.
     */
    public function __construct(public string $dir, public int $timeout = 900)
    {
    }

    public function handle(): void
    {
        file_put_contents("{$this->dir}/started", 'started');

        // Teto de 60 s: se o teste morrer antes de liberar, o worker filho
        // não fica preso aqui.
        $deadline = microtime(true) + 60;

        while (! is_file("{$this->dir}/release") && microtime(true) < $deadline) {
            usleep(100_000);
        }

        file_put_contents("{$this->dir}/done", 'done');
    }
}
