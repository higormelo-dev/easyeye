<?php

declare(strict_types=1);

/**
 * "Reagendar a série" com duas conexões PostgreSQL de verdade. A escrita lê as sessões sem trava, toma a trava da SÉRIE
 * e relê as linhas sob ela; se a série mudou nesse intervalo, a prévia que a recepção viu já não vale.
 *
 * O processo filho é "outra pessoa": toma a trava da série, altera a série e segura a transação. O principal chama o
 * service de verdade, espera o filho comitar e relê:
 *  - o filho CANCELA uma sessão (a série encolhe): o lote segue com as que restaram e a cancelada fica onde estava;
 *  - o filho ACRESCENTA uma sessão (a série cresce) ou MOVE uma remanescente: 409, nada é gravado pelo lote.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito, um de cada vez.
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Exceptions\ScheduleSeriesChangedException;
use App\Models\{DoctorWorkSchedule, Entity, Schedule, ScheduleSituationLog, User};
use App\Services\Schedules\ScheduleSeriesRescheduler;
use App\Services\ScheduleSeriesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

// O rollback do DatabaseMigrations (no fim de cada teste) recria o índice único ANTIGO do médico, que não ignora
// cancelados: as sessões canceladas + as novas no mesmo horário deste cenário o impediriam. Limpa antes.
afterEach(function () {
    DB::table('schedules')->delete();
});

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function ssrChildEnv(): array
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

/**
 * Série de 5 sessões semanais (segundas, 09:00), já comitadas.
 *
 * @return array{0: list<Schedule>, 1: Carbon} as sessões e a 1ª segunda-feira
 */
function ssrRaceSeries(): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = createDoctorForEntity($entity);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15]);

    $monday   = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
    $seriesId = (string) Str::uuid();
    $sessions = [];

    foreach (range(0, 4) as $index) {
        $sessions[] = Schedule::create([
            'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'full_name' => 'PACIENTE SERIE',
            'date_time' => $monday->copy()->addWeeks($index), 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
            'series_id' => $seriesId, 'session_number' => $index + 1, 'session_total' => 5,
        ]);
    }

    return [$sessions, $monday];
}

/**
 * Sobe o filho (que segura a trava da série por 2,5 s) e roda `$parent` assim que ele já alterou a série.
 *
 * @return array{0: mixed, 1: float, 2: string, 3: string} resultado do principal (ou a exceção), segundos esperados, saída e erro do filho
 */
function ssrWithConcurrentChange(Schedule $any, string $mode, string $argument, Closure $parent): array
{
    $marker = sys_get_temp_dir() . '/ssr-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(ssrChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/series_reschedule_child.php', (string) $any->entity_id, (string) $any->series_id, $mode, $argument, $marker, '2.5']);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a alterar a série — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // Rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $startedAt = microtime(true);

        try {
            $outcome = $parent();
        } catch (Throwable $e) {
            $outcome = $e;
        }

        $waited = microtime(true) - $startedAt;
        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}");

    return [$outcome, $waited, $result->output(), $result->errorOutput()];
}

it('[GAP] o filho cancela uma sessão enquanto o lote espera a trava: a série encolhe e o lote segue com as que restaram, sem duplicar nem renumerar', function () {
    [$sessions, $monday] = ssrRaceSeries();
    $newStart            = $monday->copy()->addWeek();

    [$outcome, $waited, $output] = ssrWithConcurrentChange(
        $sessions[0],
        'cancel',
        (string) $sessions[1]->id,
        fn () => app(ScheduleSeriesRescheduler::class)->reschedule($sessions[0], $newStart, null, null, null, null),
    );

    if ($outcome instanceof Throwable) {
        throw $outcome;
    }

    expect($output)->toContain('OK cancel')
        // O lote esperou o filho (que segura 2,5 s) e releu a série sob a trava.
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($outcome['updated'])->toBe(4);

    $cancelled = Schedule::query()->withoutGlobalScopes()->findOrFail($sessions[1]->id);
    $active    = Schedule::query()->withoutGlobalScopes()->where('series_id', $sessions[0]->series_id)
        ->where('situation', ScheduleSituation::Scheduled->value)->orderBy('date_time')->get();

    // A sessão 2 ficou cancelada onde estava (cancelada por outra pessoa, não remarcada pelo lote).
    expect($cancelled->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($cancelled->date_time->format('Y-m-d H:i'))->toBe($monday->copy()->addWeek()->format('Y-m-d H:i'))
        ->and($cancelled->cancellation_reason)->toBeNull()
        ->and($active->map->date_time->map->format('Y-m-d H:i')->all())->toBe([
            $monday->copy()->addWeeks(1)->format('Y-m-d H:i'),   // a nova sessão 1 caiu onde a 2 (cancelada) estava
            $monday->copy()->addWeeks(3)->format('Y-m-d H:i'),
            $monday->copy()->addWeeks(4)->format('Y-m-d H:i'),
            $monday->copy()->addWeeks(5)->format('Y-m-d H:i'),
        ])
        ->and($active->pluck('session_number')->all())->toBe([1, 3, 4, 5])
        ->and($active->pluck('session_total')->unique()->all())->toBe([5])
        ->and(ScheduleSituationLog::query()->whereIn('schedule_id', [$sessions[0]->id, $sessions[2]->id, $sessions[3]->id, $sessions[4]->id])->count())->toBe(4)
        ->and(ScheduleSituationLog::query()->where('schedule_id', $sessions[1]->id)->count())->toBe(0);
});

it('[GAP] o filho acrescenta uma sessão enquanto o lote espera a trava: a série cresceu, o lote responde 409 e não grava nada', function () {
    [$sessions, $monday] = ssrRaceSeries();

    [$outcome, $waited, $output] = ssrWithConcurrentChange(
        $sessions[0],
        'grow',
        $monday->copy()->addWeeks(6)->format('Y-m-d H:i'),
        fn () => app(ScheduleSeriesRescheduler::class)->reschedule($sessions[0], $monday->copy()->addWeek(), null, null, null, null),
    );

    expect($outcome)->toBeInstanceOf(ScheduleSeriesChangedException::class)
        ->and($output)->toContain('OK grow')
        ->and($waited)->toBeGreaterThan(1.0);

    // A sessão acrescentada pelo filho existe; o lote não cancelou nem criou nada.
    $rows = Schedule::query()->withoutGlobalScopes()->where('series_id', $sessions[0]->series_id)->orderBy('date_time')->get();

    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('situation')->unique()->all())->toBe([ScheduleSituation::Scheduled])
        ->and($rows->take(5)->map->date_time->map->format('Y-m-d H:i')->all())->toBe(collect(range(0, 4))->map(fn (int $w) => $monday->copy()->addWeeks($w)->format('Y-m-d H:i'))->all())
        ->and(ScheduleSituationLog::count())->toBe(0);
});

it('[GAP] o filho muda a data de uma sessão do lote enquanto ele espera a trava: 409 e nada é gravado pelo lote', function () {
    [$sessions, $monday] = ssrRaceSeries();

    [$outcome, , $output] = ssrWithConcurrentChange(
        $sessions[0],
        'move',
        $sessions[2]->id . '|' . $monday->copy()->addWeeks(2)->setTime(9, 30)->format('Y-m-d H:i:s'),
        fn () => app(ScheduleSeriesRescheduler::class)->reschedule($sessions[0], $monday->copy()->addWeek(), null, null, null, null),
    );

    expect($outcome)->toBeInstanceOf(ScheduleSeriesChangedException::class)
        ->and($output)->toContain('OK move')
        ->and(Schedule::query()->withoutGlobalScopes()->where('series_id', $sessions[0]->series_id)->count())->toBe(5)
        ->and(Schedule::query()->withoutGlobalScopes()->where('series_id', $sessions[0]->series_id)->where('situation', ScheduleSituation::Cancelled->value)->count())->toBe(0)
        ->and(ScheduleSituationLog::count())->toBe(0);
});

it('a prévia não trava: lê a série sem esperar quem a está alterando (a chave da trava é a do service)', function () {
    [$sessions, $monday] = ssrRaceSeries();

    // A chave que o filho trava é a do service (o filho usa ScheduleSeriesService::lockSeries).
    expect(ScheduleSeriesService::seriesLockKey((string) $sessions[0]->entity_id, (string) $sessions[0]->series_id))->toBeInt()->toBeGreaterThan(0);

    [$outcome] = ssrWithConcurrentChange(
        $sessions[0],
        'cancel',
        (string) $sessions[4]->id,
        fn () => app(ScheduleSeriesRescheduler::class)->preview($sessions[0], $monday->copy()->addWeek(), null, null, null),
    );

    // A prévia NÃO trava: leu a série sem esperar o filho (que ainda segurava a trava) e viu as 5 sessões.
    expect($outcome)->toBeArray()->and($outcome['total'])->toBe(5);
});

it('[GAP] store() de série de encaixe × reagendar a série na MESMA cota: a ordem cirurgia → cota → série → linhas → código serializa as duas (antes dava 40P01)', function () {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = createDoctorForEntity($entity);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15, 'fit_in_limit_morning' => 5]);

    $secretary = createEntityUser($entity, User::factory()->create(), ClientRule::Secretary->value);
    $monday    = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);

    // Série de encaixes que o lote vai mover +4 semanas: os destinos caem em monday+4w e monday+5w (cota de monday+4w, manhã).
    $seriesId = (string) Str::uuid();
    $existing = collect([0, 1])->map(fn (int $week) => Schedule::create([
        'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'full_name' => 'ENCAIXE LOTE', 'is_fit_in' => true,
        'date_time' => $monday->copy()->addWeeks($week), 'situation' => 1, 'active' => true,
        'series_id' => $seriesId, 'session_number' => $week + 1, 'session_total' => 2,
    ]));

    $marker = sys_get_temp_dir() . '/ssr-' . Str::uuid();
    $child  = Process::path(base_path())
        ->env(ssrChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/series_reschedule_child.php', (string) $entity->id, $seriesId, 'batch', $existing[0]->id . '|' . $monday->copy()->addWeeks(4)->format('Y-m-d H:i'), $marker, '2.5']);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o lote não chegou à janela — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        DB::statement("set statement_timeout = '30s'");

        // O lote segura as cotas (monday+4w e monday+5w) e a trava da série e ainda não tomou a trava do código.
        // store() de série de encaixe: 1ª data livre (monday+3w) + extra na cota do lote (monday+4w, 10:30).
        $startedAt = microtime(true);
        $response  = $this->actingAs($secretary->user)->withSession(panelSession($secretary))->postJson(route('panel.schedules.store'), [
            'doctor_id'    => $doctor->id,
            'full_name'    => 'ENCAIXE STORE',
            'date_time'    => $monday->copy()->addWeeks(3)->format('Y-m-d H:i'),
            'is_fit_in'    => true,
            'series_dates' => [$monday->copy()->addWeeks(4)->setTime(10, 30)->format('Y-m-d H:i')],
        ]);
        $waited = microtime(true) - $startedAt;
        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    $response->assertCreated();   // em falha, mostra o corpo (422 de validação, 500 de deadlock…)

    expect($result->successful())->toBeTrue("o lote morreu (deadlock?) — stderr: {$result->errorOutput()}")
        ->and($result->output())->toContain('OK batch 2')
        // O store esperou a cota que o lote segurava (2,5 s de janela) — sem nenhuma trava na mão que o lote precisasse.
        ->and($waited)->toBeGreaterThan(1.0)
        ->and(Schedule::query()->withoutGlobalScopes()->where('full_name', 'ENCAIXE STORE')->count())->toBe(2)
        ->and(Schedule::query()->withoutGlobalScopes()->where('series_id', $seriesId)->where('situation', 1)->count())->toBe(2)
        ->and(Schedule::query()->withoutGlobalScopes()->where('series_id', $seriesId)->where('situation', 9)->count())->toBe(2);
});
