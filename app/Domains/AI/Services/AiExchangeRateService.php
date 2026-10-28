<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\AiExchangeRateFetchException;
use App\Domains\AI\Models\{AiExchangeRate, AiProviderTopup};
use App\Enums\AI\AiExchangeRateSource;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB, Http, Log};
use InvalidArgumentException;
use Throwable;

/**
 * Cotação US$→R$ que precifica a IA (custo do provedor em US$ → R$ cobrado da clínica).
 *
 * `current()` é SOMENTE leitura (nunca faz HTTP) e aplica a precedência decidida no plano do lote 2 (D3):
 *   1. manual com vigência hoje (até agora)        — manual do dia vence a automática do dia;
 *   2. automática (frankfurter) buscada hoje        — a taxa pode ter data efetiva de D-1 (fim de semana/feriado);
 *   3. manual antiga (vigência antes de hoje)       — perde para a automática de hoje;
 *   4. automática antiga                             — última guardada;
 *   5. cotação da última recarga de provedor (`legacy_topup`);
 *   6. FALLBACK 5,50, sinalizado — o manager pede "defina a cotação ou busque a automática".
 *
 * A busca automática (BCE via frankfurter.dev, 1 taxa por dia útil, sem chave) é `ensureToday()` (primeira precificação
 * do dia, com cache de falha) e `fetchNow()` (job diário e botão "buscar agora"). Falha nunca derruba a precificação:
 * fica a última cotação guardada e um aviso no log. Execuções já precificadas guardam a cotação que usaram (snapshot em
 * `ai_runs`). Fonte única também do P&L e do catálogo (via AiUsdBrlRate, que é a fachada).
 */
final class AiExchangeRateService
{
    public const FALLBACK = 5.50;

    public const SOURCE_FALLBACK = 'fallback';

    /** Faixa aceita para uma cotação manual (R$ por US$): barra erro de digitação (55 em vez de 5,5). */
    public const MIN_RATE = 1.0;

    public const MAX_RATE = 20.0;

    /** Taxa automática fora disto é lixo da API (zero, erro de unidade), não câmbio. */
    public const AUTO_MIN_RATE = 0.5;

    public const AUTO_MAX_RATE = 100.0;

    public const AUTO_NOTE = 'Frankfurter/BCE';

    public const FETCH_CREATED = 'created';

    public const FETCH_ALREADY = 'already_fetched';

    public const FETCH_BUSY = 'busy';

    public const FETCH_FAILED = 'failed';

    public const FETCH_FAILED_CACHED = 'failed_cached';

    public const FETCH_DISABLED = 'disabled';

    /**
     * @return array{
     *   rate: float, source: string, effective_at: ?CarbonInterface, recorded_by: ?string, recorded_by_name: ?string,
     *   note: ?string, exchange_rate_id: ?string, is_fallback: bool, fetched_at: ?CarbonInterface, fetched_today: bool,
     *   is_automatic: bool, is_manual_override: bool, age_days: ?int
     * }
     */
    public function current(): array
    {
        $startOfDay = now()->startOfDay();
        $todayAuto  = $this->todayAutomatic();

        // Um ranking só (ordem D3) + a automática de hoje, que também responde `fetched_today`/`is_manual_override`.
        $row = AiExchangeRate::query()
            ->with('recordedBy:id,name')
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->where('source', AiExchangeRateSource::Manual->value)->where('effective_at', '<=', now()))
                ->orWhere('source', AiExchangeRateSource::Frankfurter->value))
            ->orderByRaw(
                "CASE WHEN source = 'manual' AND effective_at >= ? THEN 1
                      WHEN source = 'frankfurter' AND created_at >= ? THEN 2
                      WHEN source = 'manual' THEN 3
                      ELSE 4 END",
                [$startOfDay, $startOfDay],
            )
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id') // UUID ordenado (HasUuids): desempate determinístico no mesmo segundo
            ->first();

        if ($row !== null) {
            $isManual = $row->source === AiExchangeRateSource::Manual;

            return $this->describe(
                rate: (float) $row->rate_brl_per_usd,
                source: $row->source->value,
                effectiveAt: $row->effective_at,
                fetchedAt: $row->created_at,
                recordedBy: $row->recorded_by ? (string) $row->recorded_by : null,
                recordedByName: $row->recordedBy?->name,
                note: $row->note,
                id: (string) $row->id,
                fetchedToday: $todayAuto !== null,
                isManualOverride: $isManual && $todayAuto !== null && $row->effective_at->greaterThanOrEqualTo($startOfDay),
            );
        }

        $topup = AiProviderTopup::query()
            ->whereNotNull('exchange_rate')
            ->latest('topped_up_at')
            ->first(['exchange_rate', 'topped_up_at']);

        if ($topup !== null) {
            return $this->describe((float) $topup->exchange_rate, AiExchangeRateSource::LegacyTopup->value, $topup->topped_up_at, $topup->topped_up_at);
        }

        return $this->describe(self::FALLBACK, self::SOURCE_FALLBACK, null, null, isFallback: true);
    }

    /**
     * Garante a automática DO DIA antes da primeira precificação: já buscada → `already_fetched`; falha recente →
     * `failed_cached` (sem rede por 15 min); senão busca. Qualquer erro inesperado vira `failed` — a precificação segue
     * com a cotação vigente.
     *
     * @return array{status: string, row: ?AiExchangeRate, code: ?string}
     */
    public function ensureToday(): array
    {
        if (! config('ai.exchange_rate.auto_fetch', true)) {
            return $this->outcome(self::FETCH_DISABLED);
        }

        if (($row = $this->todayAutomatic()) !== null) {
            return $this->outcome(self::FETCH_ALREADY, $row);
        }

        if (Cache::has($this->failureKey())) {
            return $this->outcome(self::FETCH_FAILED_CACHED);
        }

        try {
            return $this->fetchNow();
        } catch (Throwable $e) {
            Log::warning('ai.exchange_rate.fetch_failed', ['code' => 'unexpected', 'error' => $e->getMessage()]);

            return $this->outcome(self::FETCH_FAILED, code: 'unexpected');
        }
    }

    /**
     * Busca a taxa de hoje (job diário e botão "buscar agora"; ignora o cache de falha). Idempotente por dia: se já há
     * automática de hoje, devolve-a. Lock de 10 s evita duas buscas simultâneas (`busy`, sem HTTP). O callback de
     * auditoria roda DENTRO da transação do insert: se a trilha falhar, a linha é desfeita (o HTTP já aconteceu, mas o
     * preço não entra em vigor sem auditoria).
     *
     * @param callable(AiExchangeRate): void|null $audit
     *
     * @return array{status: string, row: ?AiExchangeRate, code: ?string}
     */
    public function fetchNow(?callable $audit = null): array
    {
        if (($row = $this->todayAutomatic()) !== null) {
            return $this->outcome(self::FETCH_ALREADY, $row);
        }

        $lock = Cache::lock('ai:exchange-rate:fetch:' . now()->toDateString(), 10);

        if (! $lock->get()) {
            return $this->outcome(self::FETCH_BUSY);
        }

        try {
            if (($row = $this->todayAutomatic()) !== null) {
                return $this->outcome(self::FETCH_ALREADY, $row);
            }

            try {
                [$rate, $date] = $this->fetchFromFrankfurter();
            } catch (AiExchangeRateFetchException $e) {
                Log::warning('ai.exchange_rate.fetch_failed', ['code' => $e->code()]);
                Cache::put($this->failureKey(), true, now()->addMinutes((int) config('ai.exchange_rate.failure_cache_minutes', 15)));

                return $this->outcome(self::FETCH_FAILED, code: $e->code());
            }

            $created = false;
            $row     = DB::transaction(function () use ($rate, $date, $audit, &$created): AiExchangeRate {
                if (($existing = $this->todayAutomatic()) !== null) {
                    return $existing;
                }

                $row = AiExchangeRate::query()->create([
                    'source'           => AiExchangeRateSource::Frankfurter->value,
                    'rate_brl_per_usd' => number_format($rate, 6, '.', ''),
                    'effective_at'     => Carbon::parse($date)->startOfDay(),
                    'recorded_by'      => null,
                    'note'             => self::AUTO_NOTE,
                ]);
                $created = true;

                if ($audit !== null) {
                    $audit($row);
                }

                return $row;
            });

            if ($created) {
                Cache::forget($this->failureKey());
            }

            return $this->outcome($created ? self::FETCH_CREATED : self::FETCH_ALREADY, $row);
        } finally {
            $lock->release();
        }
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
            'effective_at'     => Carbon::instance($effectiveAt)->setTimezone(config('app.timezone')),
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

    /** Automática buscada hoje (dia no fuso da aplicação; `created_at` identifica a busca, não a data da taxa). */
    private function todayAutomatic(): ?AiExchangeRate
    {
        return AiExchangeRate::query()
            ->where('source', AiExchangeRateSource::Frankfurter->value)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * GET {base_url}/latest?base=USD&symbols=BRL — sem chave, sem retry, timeouts curtos. Valida status, base, data
     * (Y-m-d, não futura) e taxa (numérica, finita, 0,5–100). Nunca loga o corpo da resposta.
     *
     * @return array{0: float, 1: string} taxa e data (Y-m-d) da cotação
     *
     * @throws AiExchangeRateFetchException
     */
    private function fetchFromFrankfurter(): array
    {
        try {
            $response = Http::connectTimeout((int) config('ai.exchange_rate.connect_timeout_seconds', 3))
                ->timeout((int) config('ai.exchange_rate.timeout_seconds', 5))
                ->acceptJson()
                ->get(rtrim((string) config('ai.exchange_rate.base_url', 'https://api.frankfurter.dev/v1'), '/') . '/latest', ['base' => 'USD', 'symbols' => 'BRL']);
        } catch (ConnectionException) {
            throw new AiExchangeRateFetchException('timeout');
        }

        if (! $response->successful()) {
            throw new AiExchangeRateFetchException("http_{$response->status()}");
        }

        $date = $response->json('date');
        $rate = $this->numericRate($response->json('rates.BRL'));

        if ($response->json('base') !== 'USD' || $rate === null || ! is_string($date) || ! $this->validPastDate($date)) {
            throw new AiExchangeRateFetchException('invalid_payload');
        }

        if ($rate < self::AUTO_MIN_RATE || $rate > self::AUTO_MAX_RATE) {
            throw new AiExchangeRateFetchException('out_of_range');
        }

        return [$rate, $date];
    }

    private function validPastDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = Carbon::createFromFormat('Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date && $parsed->startOfDay()->lessThanOrEqualTo(now()->startOfDay());
    }

    /**
     * Taxa vinda da API só vale se for número de verdade: int/float finito ou string decimal íntegra ("5.4321").
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

    private function failureKey(): string
    {
        return 'ai:exchange-rate:failed:' . now()->toDateString();
    }

    /** @return array{status: string, row: ?AiExchangeRate, code: ?string} */
    private function outcome(string $status, ?AiExchangeRate $row = null, ?string $code = null): array
    {
        return ['status' => $status, 'row' => $row, 'code' => $code];
    }

    /** @return array<string, mixed> */
    private function describe(
        float $rate,
        string $source,
        ?CarbonInterface $effectiveAt,
        ?CarbonInterface $fetchedAt,
        ?string $recordedBy = null,
        ?string $recordedByName = null,
        ?string $note = null,
        ?string $id = null,
        bool $fetchedToday = false,
        bool $isManualOverride = false,
        bool $isFallback = false,
    ): array {
        return [
            'rate'               => $rate,
            'source'             => $source,
            'effective_at'       => $effectiveAt,
            'recorded_by'        => $recordedBy,
            'recorded_by_name'   => $recordedByName,
            'note'               => $note,
            'exchange_rate_id'   => $id,
            'is_fallback'        => $isFallback,
            'fetched_at'         => $fetchedAt,
            'fetched_today'      => $fetchedToday,
            'is_automatic'       => $source === AiExchangeRateSource::Frankfurter->value,
            'is_manual_override' => $isManualOverride,
            'age_days'           => $effectiveAt !== null ? (int) Carbon::instance($effectiveAt)->startOfDay()->diffInDays(now()->startOfDay()) : null,
        ];
    }
}
