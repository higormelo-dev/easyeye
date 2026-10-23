<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Job mínimo pra QueueWorkerSmokeTest (EE-09) — precisa de um namespace
 * PSR-4 de verdade (nem todo classname solto em tests/ entra no autoload
 * do Composer, confirmado pelos avisos "does not comply with psr-4...
 * Skipping" de outras fixtures do projeto): o worker real do teste é um
 * PROCESSO SEPARADO (php artisan queue:work), que só resolve a classe do
 * job via o autoloader do Composer — nunca via o que o Pest já carregou
 * no processo de teste.
 */
class Ee09MarkerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $markerPath)
    {
    }

    public function handle(): void
    {
        // hrtime (monotônico, em ns): o conteúdo diz a ORDEM em que o worker processou os jobs.
        file_put_contents($this->markerPath, (string) hrtime(true));
    }
}
