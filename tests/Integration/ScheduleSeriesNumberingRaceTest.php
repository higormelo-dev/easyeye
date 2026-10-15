<?php

declare(strict_types=1);

/**
 * Série aberta: duas sessões acrescentadas ao mesmo tempo não repetem o número, com duas conexões PostgreSQL de
 * verdade — achado via revisão adversarial Codex. `addSession` calculava o próximo número (máximo + 1) depois de um
 * `SELECT … FOR UPDATE` nas linhas da série, mas esse comando só trava (e só enxerga) as linhas que existiam no seu
 * snapshot: a segunda requisição esperava a primeira comitar e, ao acordar, NÃO via a sessão que a primeira acabara
 * de inserir — as duas gravavam o mesmo `session_number` (e não há índice único que impeça).
 *
 * O processo filho acrescenta a sessão 2 e segura a transação depois de inserir; o principal acrescenta a sua de
 * verdade (service) por cima. Com a trava da SÉRIE tomada antes da leitura, o principal espera o filho comitar e
 * numera a 3. Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito.
 */

use App\Models\{DoctorWorkSchedule, Entity, Schedule};
use App\Services\ScheduleSeriesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function ssnChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

it('[GAP] duas sessões acrescentadas à série aberta ao mesmo tempo ficam com números diferentes: a 2ª espera a trava da série e relê (achado via revisão adversarial Codex)', function () {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = createDoctorForEntity($entity);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15]);

    $monday = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
    $anchor = Schedule::create([
        'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'full_name' => 'PACIENTE SERIE',
        'date_time' => $monday, 'situation' => 1, 'active' => true,
    ]);
    app(ScheduleSeriesService::class)->startOpenSeries($anchor);
    $anchor->refresh();

    $marker = sys_get_temp_dir() . '/ssn-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(ssnChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/series_add_session_child.php', (string) $anchor->id, $monday->copy()->addWeek()->format('Y-m-d H:i'), $marker, '2.5']);

    $mine = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a inserir a sessão — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // Rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $startedAt = microtime(true);
        $mine      = DB::transaction(fn (): Schedule => app(ScheduleSeriesService::class)->addSession($anchor->fresh(), $monday->copy()->addWeeks(2)));
        $waited    = microtime(true) - $startedAt;
        $result    = $child->wait();
    } finally {
        @unlink($marker);
    }

    $numbers = Schedule::query()->withoutGlobalScopes()->where('series_id', $anchor->series_id)->orderBy('session_number')->pluck('session_number')->map(fn ($n) => (int) $n)->all();

    expect($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}")
        ->and($result->output())->toContain('OK 2')
        // A 2ª esperou o filho (que segura 2,5 s) e numerou a sessão seguinte, não a mesma.
        ->and($waited)->toBeGreaterThan(1.0)
        ->and((int) $mine->session_number)->toBe(3)
        ->and($numbers)->toBe([1, 2, 3]);
});
