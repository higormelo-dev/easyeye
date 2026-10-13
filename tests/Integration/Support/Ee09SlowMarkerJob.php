<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Job "longo" pro QueueRedisReservationTest: declara o mesmo $timeout dos
 * imports mais longos da aplicação (900 s), mas só segura a reserva até o
 * teste liberar — sincronizado por arquivos, sem sleep fixo. Mesmo motivo do
 * Ee09MarkerJob pra estar em namespace PSR-4: o worker é um processo
 * separado e só resolve a classe pelo autoloader do Composer.
 */
class Ee09SlowMarkerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $dir)
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
