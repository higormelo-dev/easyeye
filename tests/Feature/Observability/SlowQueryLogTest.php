<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\{Context, DB, Log, Route};

/**
 * F0 "Enxergar": query acima de 500 ms de duração ACUMULADA da conexão loga só metadados — conexão, duração, total e
 * rota/comando/job. Nunca `sql`, `bindings`, URL completa ou dado de paciente (LGPD; decisão D2 do plano de performance).
 */
class SlowQueryProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function handle(): void
    {
        DB::select('select pg_sleep(0.6)');

        if ($this->fail) {
            throw new RuntimeException('probe falhou');
        }
    }
}

/** Zera o acumulado da conexão e rearma o gatilho: o RefreshDatabase já gastou tempo nela antes do teste. */
function armSlowQueryHandler(): void
{
    $connection = DB::connection();
    $connection->resetTotalQueryDuration();
    $connection->allowQueryDurationHandlersToRunAgain();
}

/** Contexto do único `db.slow_query` registrado no Log::spy(). @return array<string, mixed> */
function slowQueryContext(): array
{
    $captured = [];

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            if ($message === 'db.slow_query') {
                $captured = $context;
            }

            return $message === 'db.slow_query';
        })
        ->once();

    return $captured;
}

it('query lenta loga conexão, duração, total, rota, comando e job — e nenhum sql/bindings', function () {
    Log::spy();
    armSlowQueryHandler();

    // 2 × 300 ms: o gatilho (500 ms) é o ACUMULADO, então dispara na 2ª — a duração é a da query corrente, o total soma as duas.
    DB::select('select pg_sleep(0.3)');
    DB::select('select pg_sleep(0.3) where ? = ?', ['segredo-paciente', 'segredo-paciente']);

    $context = slowQueryContext();

    expect(array_keys($context))->toBe(['connection', 'duration_ms', 'total_duration_ms', 'route', 'command', 'job'])
        ->and($context['connection'])->toBe(config('database.default'))
        ->and($context['duration_ms'])->toBeGreaterThanOrEqual(300.0)->toBeLessThan(550.0)
        ->and($context['total_duration_ms'])->toBeGreaterThanOrEqual(600.0)
        ->and($context['total_duration_ms'] - $context['duration_ms'])->toBeGreaterThanOrEqual(250.0)
        ->and($context['route'])->toBeNull()
        ->and($context['command'])->toBe($_SERVER['argv'][1] ?? null)
        ->and($context['job'])->toBeNull()
        ->and(json_encode($context))->not->toContain('segredo-paciente')->not->toContain('pg_sleep');
});

it('abaixo de 500 ms acumulados não loga', function () {
    Log::spy();
    armSlowQueryHandler();

    DB::select('select 1');
    DB::select('select pg_sleep(0.2)');

    Log::shouldNotHaveReceived('warning');
});

it('numa requisição loga o NOME da rota, nunca a URL', function () {
    Log::spy();
    Route::get('/observability/pacientes/{cpf}', function (): string {
        DB::select('select pg_sleep(0.6)');

        return 'ok';
    })->name('observability.slow');

    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);
    armSlowQueryHandler();

    $this->get('/observability/pacientes/12345678900')->assertOk();

    $context = slowQueryContext();

    expect($context['route'])->toBe('observability.slow')
        ->and($context['command'])->toBeNull()
        ->and(json_encode($context))->not->toContain('12345678900');
});

it('dentro de um job loga a classe do job e limpa o contexto ao terminar', function () {
    Log::spy();
    armSlowQueryHandler();

    SlowQueryProbeJob::dispatch();

    expect(slowQueryContext()['job'])->toBe(SlowQueryProbeJob::class)
        ->and(Context::has('job'))->toBeFalse();
});

it('job que falha também não deixa o nome no contexto', function () {
    Log::spy();
    armSlowQueryHandler();

    expect(fn () => SlowQueryProbeJob::dispatch(fail: true))->toThrow(RuntimeException::class)
        ->and(slowQueryContext()['job'])->toBe(SlowQueryProbeJob::class)
        ->and(Context::has('job'))->toBeFalse();
});
