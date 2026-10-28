<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AI\FetchAiExchangeRateJob;
use Illuminate\Console\Command;

/**
 * Agendado diariamente (routes/console.php): só enfileira o job que busca a cotação automática do dólar de hoje —
 * o agendador roda em série e não pode esperar a rede. Busca síncrona: AiExchangeRateService::fetchNow().
 */
class FetchAiExchangeRateCommand extends Command
{
    protected $signature = 'ai:fetch-exchange-rate';

    protected $description = 'Enfileira a busca da cotação do dólar do dia (frankfurter.dev) para a precificação da IA.';

    public function handle(): int
    {
        FetchAiExchangeRateJob::dispatch();

        $this->info('Busca da cotação do dólar enfileirada (fila default).');

        return self::SUCCESS;
    }
}
