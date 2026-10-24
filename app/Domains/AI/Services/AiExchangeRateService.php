<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\{AiExchangeRate, AiProviderTopup};
use App\Enums\AI\AiExchangeRateSource;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Cache, Http, Log};
use InvalidArgumentException;
use Throwable;

/**
 * Cotação US$→R$ que precifica a IA (custo do provedor em US$ → R$ cobrado da clínica).
 *
 * Cotação vigente, nesta ordem:
 *   1. a última linha de `ai_exchange_rates` com `effective_at <= agora` (cotação gravada no manager);
 *   2. senão, a cotação da última recarga de provedor (`ai_provider_topups`), como `legacy_topup`;
 *   3. senão, FALLBACK (5,50), sinalizado como estimativa — o manager pede "defina a cotação".
 *
 * Execuções já precificadas guardam a cotação que usaram (snapshot em `ai_runs`): mudar a cotação aqui só afeta as
 * estimativas seguintes. Fonte única também do P&L e do catálogo (via AiUsdBrlRate, que é a fachada).
 */
final class AiExchangeRateService
{
    public const FALLBACK = 5.50;

    public const SOURCE_FALLBACK = 'fallback';

    /** Faixa aceita para uma cotação manual (R$ por US$): barra erro de digitação (55 em vez de 5,5). */
    public const MIN_RATE = 1.0;

    public const MAX_RATE = 20.0;

    /** Cotação automática: taxa diária do BCE publicada pelo frankfurter.dev (grátis, sem chave). */
    public const FRANKFURTER_URL = 'https://api.frankfurter.dev/v1/latest?base=USD&symbols=BRL';

    /** Taxa automática fora disto é lixo da API (zero, erro de unidade), não câmbio. */
    public const AUTO_MIN_RATE = 0.5;

    public const AUTO_MAX_RATE = 100.0;

    /**
     * Garante a cotação automática DO DIA: a primeira consulta do dia busca no frankfurter e grava
     * (source=frankfurter, effective_at=data da taxa); as seguintes leem do banco. Falha de rede/API ou
     * taxa inválida: nada é gravado, fica a última cotação guardada (aviso no log) e a próxima tentativa
     * espera 15 min. Uma cotação manual gravada hoje dispensa a busca (ela vence a automática do dia).
     */
    public function ensureTodayRate(): ?AiExchangeRate
    {
        if (! config('ai.exchange_rate.auto_fetch', true)) {
            return null;
        }

        $today = now()->toDateString();

        $existing = AiExchangeRate::query()
            ->whereDate('effective_at', $today)
            ->whereIn('source', [AiExchangeRateSource::Frankfurter->value, AiExchangeRateSource::Manual->value])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Falha recente: não tenta de novo por 15 min (Cache::remember trataria null como falta e repetiria a rede).
        $cooldownKey = "ai_exchange_rate:frankfurter:cooldown:{$today}";

        if (Cache::has($cooldownKey)) {
            return null;
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get(self::FRANKFURTER_URL);
            $rate     = $this->numericRate($response->json('rates.BRL'));
            $date     = $response->json('date');
            $date     = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : $today;

            if (! $response->ok() || $rate === null || $rate < self::AUTO_MIN_RATE || $rate > self::AUTO_MAX_RATE) {
                Log::warning('ai.exchange_rate.frankfurter_invalid', ['status' => $response->status(), 'rate' => is_scalar($rate) ? $rate : gettype($rate)]);
                Cache::put($cooldownKey, true, now()->addMinutes(15));

                return null;
            }

            // A taxa do BCE é de um dia útil: fim de semana devolve a de sexta — serve como cotação do dia.
            return AiExchangeRate::query()->firstOrCreate(
                ['source' => AiExchangeRateSource::Frankfurter->value, 'effective_at' => now()->startOfDay()],
                ['rate_brl_per_usd' => number_format($rate, 6, '.', ''), 'note' => "BCE {$date} via frankfurter.dev"],
            );
        } catch (Throwable $e) {
            Log::warning('ai.exchange_rate.frankfurter_failed', ['error' => $e->getMessage()]);
            Cache::put($cooldownKey, true, now()->addMinutes(15));

            return null;
        }
    }

    /**
     * Taxa vinda da API só vale se for número de verdade: int/float finito ou string decimal inteira ("5.4321").
     * Booleano, array, objeto, null e "5.4lixo" NUNCA viram câmbio (um cast cego faria true virar 1,0).
     */
    private function numericRate(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return is_finite((float) $raw) ? (float) $raw : null;
        }

        if (is_string($raw) && preg_match('/^\d+(\.\d+)?$/', trim($raw)) === 1) {
            return (float) trim($raw);
        }

        return null;
    }

    /**
     * @return array{rate: float, source: string, effective_at: ?CarbonInterface, recorded_by: ?string, recorded_by_name: ?string, note: ?string, exchange_rate_id: ?string, is_fallback: bool}
     */
    public function current(): array
    {
        $this->ensureTodayRate();

        // Precedência: no mesmo dia a MANUAL vence a automática; entre dias, o mais recente vence.
        $row = AiExchangeRate::query()
            ->with('recordedBy:id,name')
            ->where('effective_at', '<=', now())
            ->orderByRaw('DATE(effective_at) DESC')
            ->orderByRaw("CASE WHEN source = 'manual' THEN 0 ELSE 1 END")
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id') // UUID ordenado (HasUuids): desempate determinístico no mesmo segundo
            ->first();

        if ($row !== null) {
            return [
                'rate'             => (float) $row->rate_brl_per_usd,
                'source'           => $row->source->value,
                'effective_at'     => $row->effective_at,
                'recorded_by'      => $row->recorded_by ? (string) $row->recorded_by : null,
                'recorded_by_name' => $row->recordedBy?->name,
                'note'             => $row->note,
                'exchange_rate_id' => (string) $row->id,
                'is_fallback'      => false,
            ];
        }

        $topup = AiProviderTopup::query()
            ->whereNotNull('exchange_rate')
            ->latest('topped_up_at')
            ->first(['exchange_rate', 'topped_up_at']);

        if ($topup !== null) {
            return [
                'rate'             => (float) $topup->exchange_rate,
                'source'           => AiExchangeRateSource::LegacyTopup->value,
                'effective_at'     => $topup->topped_up_at,
                'recorded_by'      => null,
                'recorded_by_name' => null,
                'note'             => null,
                'exchange_rate_id' => null,
                'is_fallback'      => false,
            ];
        }

        return [
            'rate'             => self::FALLBACK,
            'source'           => self::SOURCE_FALLBACK,
            'effective_at'     => null,
            'recorded_by'      => null,
            'recorded_by_name' => null,
            'note'             => null,
            'exchange_rate_id' => null,
            'is_fallback'      => true,
        ];
    }

    /** Grava uma nova cotação manual (histórico append-only: nunca edita uma linha antiga). */
    public function record(float $rateBrlPerUsd, CarbonInterface $effectiveAt, string $recordedBy, ?string $note = null): AiExchangeRate
    {
        if (! is_finite($rateBrlPerUsd) || $rateBrlPerUsd < self::MIN_RATE || $rateBrlPerUsd > self::MAX_RATE) {
            throw new InvalidArgumentException('ai_exchange_rate_out_of_range');
        }

        return AiExchangeRate::query()->create([
            'source'           => AiExchangeRateSource::Manual->value,
            'rate_brl_per_usd' => number_format($rateBrlPerUsd, 6, '.', ''),
            'effective_at'     => $effectiveAt,
            'recorded_by'      => $recordedBy,
            'note'             => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
        ]);
    }

    /** @return LengthAwarePaginator<int, AiExchangeRate> */
    public function history(int $perPage = 15): LengthAwarePaginator
    {
        return AiExchangeRate::query()
            ->with('recordedBy:id,name')
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
