<?php

declare(strict_types=1);

use App\Domains\AI\Models\{AiExchangeRate, AiProviderTopup};
use App\Domains\AI\Services\AiExchangeRateService;
use App\Jobs\AI\FetchAiExchangeRateJob;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Http, Log, Queue};

/*
 * Câmbio automático da precificação de IA (plano docs/internal/planos/ai-pricing-lote2-plano.md §2): uma taxa do BCE por
 * dia via frankfurter.dev, gravada com source=frankfurter; `current()` é SOMENTE leitura e aplica a precedência D3
 * (manual de hoje > automática de hoje > manual antiga > automática antiga > recarga legada > contingência).
 */

const FRANKFURTER_PATTERN = 'api.frankfurter.dev/*';

/** @return array<string, mixed> */
function frankfurterPayload(float|string|bool|array|int $rate = 5.4321, ?string $date = null, array $overrides = []): array
{
    return [...['base' => 'USD', 'date' => $date ?? now()->toDateString(), 'rates' => ['BRL' => $rate]], ...$overrides];
}

function frankfurterRates(): AiExchangeRateService
{
    return app(AiExchangeRateService::class);
}

beforeEach(function () {
    config(['ai.exchange_rate.auto_fetch' => true]);
    // Hora fixa no fuso da aplicação: nada aqui pode depender de "agora" real nem virar o dia no meio do teste.
    $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));
    Http::preventStrayRequests();
});

describe('busca automática', function () {
    it('a primeira precificação do dia busca a taxa do BCE e grava UMA linha automática com data efetiva, sem autor', function () {
        Http::fake([FRANKFURTER_PATTERN => Http::response(frankfurterPayload(5.4321, '2026-10-21'))]);

        $result = frankfurterRates()->ensureToday();

        expect($result['status'])->toBe(AiExchangeRateService::FETCH_CREATED)
            ->and($result['row'])->toBeInstanceOf(AiExchangeRate::class);

        $row = AiExchangeRate::query()->sole();

        expect($row->source->value)->toBe('frankfurter')
            ->and((float) $row->rate_brl_per_usd)->toBe(5.4321)
            ->and($row->effective_at->format('Y-m-d H:i'))->toBe('2026-10-21 00:00')
            ->and($row->recorded_by)->toBeNull()
            ->and($row->note)->toBe('Frankfurter/BCE');

        $current = frankfurterRates()->current();

        expect($current)->toMatchArray(['rate' => 5.4321, 'source' => 'frankfurter', 'is_fallback' => false, 'is_automatic' => true, 'fetched_today' => true, 'is_manual_override' => false, 'age_days' => 1])
            ->and($current['fetched_at']?->toIso8601String())->toBe($row->created_at->toIso8601String())
            ->and($current['exchange_rate_id'])->toBe((string) $row->id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/latest?base=USD&symbols=BRL') && $request->hasHeader('Accept', 'application/json'));
    });

    it('é idempotente no dia: a segunda chamada devolve already_fetched sem nova requisição nem nova linha', function () {
        Http::fake([FRANKFURTER_PATTERN => Http::response(frankfurterPayload())]);

        expect(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_CREATED)
            ->and(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_ALREADY)
            ->and(frankfurterRates()->fetchNow()['status'])->toBe(AiExchangeRateService::FETCH_ALREADY)
            ->and(AiExchangeRate::query()->count())->toBe(1);

        Http::assertSentCount(1);
    });

    it('vira o dia no fuso local: 23:59 e 00:01 geram uma linha cada e a vigente passa a ser a nova', function () {
        Http::fake([FRANKFURTER_PATTERN => Http::sequence()
            ->push(frankfurterPayload(5.40, '2026-10-22'))
            ->push(frankfurterPayload(5.60, '2026-10-23'))]);

        $this->travelTo(Carbon::parse('2026-10-22 23:59:00'));
        expect(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_CREATED)
            ->and(frankfurterRates()->current())->toMatchArray(['rate' => 5.4, 'fetched_today' => true]);

        $this->travelTo(Carbon::parse('2026-10-23 00:01:00'));
        expect(frankfurterRates()->current())->toMatchArray(['rate' => 5.4, 'fetched_today' => false, 'age_days' => 1])
            ->and(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_CREATED)
            ->and(frankfurterRates()->current())->toMatchArray(['rate' => 5.6, 'fetched_today' => true, 'age_days' => 0])
            ->and(AiExchangeRate::query()->count())->toBe(2);

        Http::assertSentCount(2);
    });

    it('API fora ou tempo esgotado: nada é gravado, a última cotação continua, há aviso no log e a rede não é repetida por 15 min', function (callable $fake, string $code) {
        $user = User::factory()->create();
        frankfurterRates()->record(5.10, now()->subDay(), (string) $user->id);
        Http::fake([FRANKFURTER_PATTERN => $fake()]);
        Log::spy();

        $result = frankfurterRates()->ensureToday();

        expect($result)->toMatchArray(['status' => AiExchangeRateService::FETCH_FAILED, 'code' => $code])
            ->and(AiExchangeRate::query()->where('source', 'frankfurter')->count())->toBe(0)
            ->and(frankfurterRates()->current())->toMatchArray(['rate' => 5.1, 'source' => 'manual', 'fetched_today' => false])
            ->and(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_FAILED_CACHED);

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx = []) => $msg === 'ai.exchange_rate.fetch_failed' && ($ctx['code'] ?? null) === $code && ! isset($ctx['body']))->once();

        // Passados 15 min, tenta de novo; "buscar agora" (fetchNow) ignora o cache de falha.
        $this->travel(16)->minutes();
        expect(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_FAILED);
        expect(frankfurterRates()->fetchNow()['status'])->toBe(AiExchangeRateService::FETCH_FAILED);
        Http::assertSentCount(3);
    })->with([
        'HTTP 503' => [fn () => Http::response(null, 503), 'http_503'],
        'HTTP 500' => [fn () => Http::response('<html>erro</html>', 500), 'http_500'],
        'timeout'  => [fn () => Http::failedConnection('cURL error 28: Operation timed out'), 'timeout'],
    ]);

    it('um sucesso depois de uma falha no mesmo dia limpa o cache de falha', function () {
        Http::fake([FRANKFURTER_PATTERN => Http::sequence()->push(null, 503)->push(frankfurterPayload())]);
        $failureKey = 'ai:exchange-rate:failed:' . now()->toDateString();

        expect(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_FAILED)
            ->and(Cache::has($failureKey))->toBeTrue()
            ->and(frankfurterRates()->fetchNow()['status'])->toBe(AiExchangeRateService::FETCH_CREATED)
            ->and(Cache::has($failureKey))->toBeFalse()
            ->and(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_ALREADY);

        Http::assertSentCount(2);
    });

    it('JSON inválido ou lixo não vira câmbio: failed sem linha', function (array $payload, string $code) {
        Http::fake([FRANKFURTER_PATTERN => Http::response($payload)]);

        expect(frankfurterRates()->fetchNow())->toMatchArray(['status' => AiExchangeRateService::FETCH_FAILED, 'code' => $code])
            ->and(AiExchangeRate::query()->count())->toBe(0)
            ->and(frankfurterRates()->current()['source'])->toBe('fallback');
    })->with([
        // Datas explícitas: o dataset é montado antes do travelTo do beforeEach.
        'sem rates'       => [['base' => 'USD', 'date' => '2026-10-22'], 'invalid_payload'],
        'BRL booleano'    => [frankfurterPayload(true, '2026-10-22'), 'invalid_payload'],
        'BRL array'       => [frankfurterPayload([5.4], '2026-10-22'), 'invalid_payload'],
        'BRL string suja' => [frankfurterPayload('5.4garbage', '2026-10-22'), 'invalid_payload'],
        'base errada'     => [frankfurterPayload(5.4, '2026-10-22', ['base' => 'EUR']), 'invalid_payload'],
        'data inválida'   => [frankfurterPayload(5.4, 'ontem'), 'invalid_payload'],
        'data futura'     => [frankfurterPayload(5.4, '2026-10-23'), 'invalid_payload'],
        'taxa zero'       => [frankfurterPayload(0, '2026-10-22'), 'out_of_range'],
        'abaixo da faixa' => [frankfurterPayload(0.49, '2026-10-22'), 'out_of_range'],
        'acima da faixa'  => [frankfurterPayload(100.01, '2026-10-22'), 'out_of_range'],
    ]);

    it('aceita os limites da faixa automática (0,5 e 100) e string decimal íntegra', function (float|string $rate, float $expected) {
        Http::fake([FRANKFURTER_PATTERN => Http::response(frankfurterPayload($rate))]);

        expect(frankfurterRates()->fetchNow()['status'])->toBe(AiExchangeRateService::FETCH_CREATED)
            ->and(frankfurterRates()->current()['rate'])->toBe($expected);
    })->with([
        'mínimo' => [0.5, 0.5],
        'máximo' => [100, 100.0],
        'string' => ['5.4321', 5.4321],
    ]);

    it('com o lock do dia ocupado devolve busy sem ir à rede', function () {
        Http::fake();
        $lock = Cache::lock('ai:exchange-rate:fetch:2026-10-22', 10);
        expect($lock->get())->toBeTrue();

        expect(frankfurterRates()->fetchNow()['status'])->toBe(AiExchangeRateService::FETCH_BUSY)
            ->and(AiExchangeRate::query()->count())->toBe(0);

        Http::assertNothingSent();
        $lock->release();
    });

    it('a auditoria do botão roda dentro da transação: se ela falhar, a linha automática é desfeita', function () {
        Http::fake([FRANKFURTER_PATTERN => Http::response(frankfurterPayload())]);

        expect(fn () => frankfurterRates()->fetchNow(fn () => throw new RuntimeException('auditoria indisponível')))
            ->toThrow(RuntimeException::class, 'auditoria indisponível')
            ->and(AiExchangeRate::query()->count())->toBe(0);

        $seen   = null;
        $result = frankfurterRates()->fetchNow(function (AiExchangeRate $row) use (&$seen): void {
            $seen = $row;
        });

        expect($result['status'])->toBe(AiExchangeRateService::FETCH_CREATED)
            ->and($seen?->id)->toBe($result['row']->id);
    });

    it('current() nunca faz HTTP: sem linha devolve a contingência sinalizada', function () {
        expect(frankfurterRates()->current())->toMatchArray(['rate' => 5.5, 'source' => 'fallback', 'is_fallback' => true, 'is_automatic' => false, 'fetched_today' => false, 'age_days' => null, 'fetched_at' => null]);
    });

    it('com a busca automática desligada, ensureToday não vai à rede', function () {
        config(['ai.exchange_rate.auto_fetch' => false]);
        Http::fake();

        expect(frankfurterRates()->ensureToday()['status'])->toBe(AiExchangeRateService::FETCH_DISABLED);
        Http::assertNothingSent();
    });
});

describe('precedência (D3)', function () {
    function autoRateOn(string $day, float $rate): AiExchangeRate
    {
        $previous = Carbon::now();
        test()->travelTo(Carbon::parse("{$day} 06:05:00"));
        Http::fake([FRANKFURTER_PATTERN => Http::response(frankfurterPayload($rate, $day))]);
        $row = frankfurterRates()->fetchNow()['row'];
        test()->travelTo($previous);

        return $row;
    }

    it('manual de hoje vence a automática de hoje e marca a sobreposição', function () {
        $user = User::factory()->create();
        autoRateOn('2026-10-22', 5.40);
        frankfurterRates()->record(5.75, now()->subHour(), (string) $user->id, 'ajuste de hoje');

        expect(frankfurterRates()->current())->toMatchArray(['rate' => 5.75, 'source' => 'manual', 'is_manual_override' => true, 'fetched_today' => true, 'is_automatic' => false, 'recorded_by_name' => $user->name, 'note' => 'ajuste de hoje']);
    });

    it('automática de hoje vence a manual de ontem', function () {
        $user = User::factory()->create();
        frankfurterRates()->record(5.90, now()->subDay(), (string) $user->id, 'antiga');
        autoRateOn('2026-10-22', 5.40);

        expect(frankfurterRates()->current())->toMatchArray(['rate' => 5.4, 'source' => 'frankfurter', 'is_manual_override' => false]);
    });

    it('manual de ontem vence a automática de ontem; automática antiga vence a recarga; recarga vence a contingência', function () {
        AiProviderTopup::query()->create(['provider' => 'openai', 'amount_usd' => 100, 'amount_brl' => 520, 'exchange_rate' => 5.2, 'topped_up_at' => now()->subDays(10)]);
        expect(frankfurterRates()->current())->toMatchArray(['rate' => 5.2, 'source' => 'legacy_topup', 'age_days' => 10]);

        autoRateOn('2026-10-21', 5.40);
        expect(frankfurterRates()->current())->toMatchArray(['rate' => 5.4, 'source' => 'frankfurter', 'fetched_today' => false, 'age_days' => 1]);

        $user = User::factory()->create();
        frankfurterRates()->record(5.90, now()->subDay()->subHour(), (string) $user->id, 'manual de ontem');
        expect(frankfurterRates()->current())->toMatchArray(['rate' => 5.9, 'source' => 'manual', 'age_days' => 1]);

        // Manual agendada para o futuro ainda não vale.
        frankfurterRates()->record(6.10, now()->addDay(), (string) $user->id);
        expect(frankfurterRates()->current()['rate'])->toBe(5.9);
    });

    it('manual gravada hoje DEPOIS da automática volta a valer como manual', function () {
        $user = User::factory()->create();
        Http::fake([FRANKFURTER_PATTERN => Http::response(frankfurterPayload(5.40))]);
        frankfurterRates()->ensureToday();
        expect(frankfurterRates()->current()['source'])->toBe('frankfurter');

        frankfurterRates()->record(5.75, now(), (string) $user->id);
        expect(frankfurterRates()->current())->toMatchArray(['source' => 'manual', 'rate' => 5.75, 'is_manual_override' => true]);
    });
});

describe('job diário', function () {
    it('o comando agendado só enfileira o job na fila default (o agendador não espera a rede)', function () {
        Queue::fake();
        Http::fake();

        $this->artisan('ai:fetch-exchange-rate')->assertSuccessful();

        Queue::assertPushed(FetchAiExchangeRateJob::class, fn (FetchAiExchangeRateJob $job) => $job->queue === 'default' && $job->tries === 1 && $job->timeout === 10);
        Http::assertNothingSent();
    });

    it('o job busca a taxa de hoje e, em falha sem cotação anterior, registra erro (com cotação anterior, aviso)', function () {
        Http::fake([FRANKFURTER_PATTERN => Http::sequence()->push(null, 503)->push(frankfurterPayload(5.40, '2026-10-22'))]);
        Log::spy();

        (new FetchAiExchangeRateJob())->handle(frankfurterRates());
        Log::shouldHaveReceived('error')->withArgs(fn (string $msg) => $msg === 'ai.exchange_rate.job_failed')->once();

        $this->travel(16)->minutes();
        (new FetchAiExchangeRateJob())->handle(frankfurterRates());

        expect(AiExchangeRate::query()->where('source', 'frankfurter')->count())->toBe(1)
            ->and(frankfurterRates()->current()['rate'])->toBe(5.4);
        Log::shouldHaveReceived('info')->withArgs(fn (string $msg) => $msg === 'ai.exchange_rate.job_finished')->once();
    });
});
