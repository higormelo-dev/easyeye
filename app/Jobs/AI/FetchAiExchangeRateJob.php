<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Domains\AI\Services\AiExchangeRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Busca diária da cotação automática do dólar (BCE via frankfurter.dev) — caminho do agendador. O botão "buscar agora"
 * e a primeira precificação do dia chamam o serviço de forma síncrona. Falha nunca derruba nada: fica a última cotação
 * guardada; sem cotação alguma é erro (a precificação está na contingência).
 */
class FetchAiExchangeRateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Sem retentativa: a próxima precificação do dia (ou o botão) tenta de novo; o serviço já tem cache de falha. */
    public int $tries = 1;

    /** Um GET com timeout de 5 s + um insert. */
    public int $timeout = 10;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AiExchangeRateService $rates): void
    {
        $result = $rates->fetchNow();

        if ($result['status'] === AiExchangeRateService::FETCH_FAILED) {
            $current = $rates->current();
            $level   = $current['is_fallback'] ? 'error' : 'warning';
            Log::{$level}('ai.exchange_rate.job_failed', ['code' => $result['code'], 'current_source' => $current['source']]);

            return;
        }

        Log::info('ai.exchange_rate.job_finished', ['status' => $result['status'], 'rate' => $result['row'] ? (float) $result['row']->rate_brl_per_usd : null]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('ai.exchange_rate.job_failed', ['code' => 'unexpected', 'error' => $e?->getMessage()]);
    }
}
