<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Services\AiExchangeRateService;
use Illuminate\Console\Command;

/** Busca (se ainda não há) a cotação automática do dólar de hoje — o mesmo que a primeira operação de IA do dia faria. */
class FetchAiExchangeRateCommand extends Command
{
    protected $signature = 'ai:fetch-exchange-rate';

    protected $description = 'Busca a cotação do dólar do dia (frankfurter.dev) para a precificação da IA.';

    public function handle(AiExchangeRateService $rates): int
    {
        $row = $rates->ensureTodayRate();
        $now = $rates->current();

        $this->info($row !== null
            ? "Cotação de hoje: R$ {$row->rate_brl_per_usd} ({$row->source->value})."
            : "Sem cotação nova; em vigor: R$ {$now['rate']} ({$now['source']}).");

        return self::SUCCESS;
    }
}
