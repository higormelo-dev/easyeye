<?php

declare(strict_types=1);

/**
 * Pós-operatório (Fase 4 / M5) com conexões PostgreSQL de verdade:
 *
 *  1. Dois workers processando o MESMO evento "cirurgia realizada" ao mesmo
 *     tempo (duplo despacho / reentrega) criam UM jogo de retornos — o
 *     INSERT … ON CONFLICT do plano e a reivindicação atômica de cada retorno
 *     impedem agendamento duplicado, e nenhum worker falha.
 *  2. Duas requisições de alta do MESMO caso ao mesmo tempo: uma dá a alta, a
 *     outra devolve o caso já com alta; um só documento de alta, retornos
 *     futuros cancelados uma vez.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito.
 */

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\{DoctorWorkSchedule, Schedule};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase, SurgicalDescription, SurgicalPostopEvaluation, SurgicalPostopReturn};
use App\Services\Surgery\PostOp\PostOpReturnService;
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function spiChildEnv(): array
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

/** @return array{world: object, case: SurgicalCase} */
function spiScenario(): array
{
    $world = surgeryWorld();

    foreach ([1, 2, 3, 4, 5] as $day) {
        DoctorWorkSchedule::query()->create(['doctor_id' => $world->doctor->id, 'day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }

    $world->doctor->update(['schedule_interval' => 30]);

    $case = app(SurgicalCaseService::class)->create((string) $world->entity->id, [
        'patient_id' => (string) $world->patient->id, 'surgeon_doctor_id' => (string) $world->doctor->id,
        'title'      => 'Facoemulsificação', 'eye' => 'od', 'procedures' => [['description' => 'Faco']],
    ]);
    $case->forceFill(['status' => SurgicalCaseStatus::Performed, 'performed_at' => Carbon::now()])->save();

    return ['world' => $world, 'case' => $case->refresh()];
}

/** @param list<list<string>> $commands */
function spiRaceChildren(array $commands, string $go): array
{
    $children = array_map(fn (array $command) => Process::path(base_path())->env(spiChildEnv())->timeout(90)->start($command), $commands);

    usleep(1_500_000); // dá tempo de os filhos subirem e ficarem esperando a largada
    file_put_contents($go, 'go');

    return array_map(fn ($child) => $child->wait(), $children);
}

it('dois workers do mesmo evento "cirurgia realizada" ao mesmo tempo criam um só jogo de retornos', function () {
    ['case' => $case] = spiScenario();

    $go      = sys_get_temp_dir() . '/spi-go-' . Str::uuid();
    $command = [PHP_BINARY, 'tests/Integration/Support/postop_returns_child.php', (string) $case->id, $go];

    try {
        $results = spiRaceChildren([$command, $command, $command], $go);
    } finally {
        @unlink($go);
    }

    foreach ($results as $result) {
        expect($result->successful())->toBeTrue("worker falhou — stderr: {$result->errorOutput()}");
    }

    $schedules = Schedule::query()->withoutGlobalScopes()->where('surgical_case_id', $case->id)->get();

    expect($schedules)->toHaveCount(3)
        ->and($schedules->pluck('series_id')->unique())->toHaveCount(1)
        ->and($schedules->pluck('session_number')->sort()->values()->all())->toBe([1, 2, 3])
        ->and($schedules->pluck('date_time')->map->format('Y-m-d H:i')->unique())->toHaveCount(3)
        ->and(SurgicalPostopReturn::query()->withoutGlobalScopes()->where('surgical_case_id', $case->id)->where('status', 'scheduled')->count())->toBe(3)
        ->and(SurgicalPostopReturn::query()->withoutGlobalScopes()->where('surgical_case_id', $case->id)->count())->toBe(3);
});

it('duas altas simultâneas do mesmo caso: uma efetiva, a outra devolve o caso já com alta — um só documento', function () {
    ['world' => $world, 'case' => $case] = spiScenario();

    // Retornos já planejados (a alta cancela os que seguem abertos).
    app(PostOpReturnService::class)->plan((string) $case->id);

    $evaluation = SurgicalPostopEvaluation::query()->create([
        'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'patient_id' => $case->patient_id,
        'doctor_id' => $case->surgeon_doctor_id, 'milestone' => 'd1', 'eye' => 'od', 'evaluated_at' => Carbon::now(), 'findings' => [],
    ]);
    DB::table('surgical_postop_evaluations')->where('id', $evaluation->id)->update(['status' => 'finalized', 'finalized_at' => now(), 'content_hash' => str_repeat('a', 64)]);

    $description = SurgicalDescription::query()->create([
        'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'patient_id' => $case->patient_id, 'surgeon_doctor_id' => $case->surgeon_doctor_id,
    ]);
    DB::table('surgical_descriptions')->where('id', $description->id)->update(['status' => 'finalized', 'finalized_at' => now(), 'content_hash' => str_repeat('b', 64)]);

    $go      = sys_get_temp_dir() . '/spi-go-' . Str::uuid();
    $command = [PHP_BINARY, 'tests/Integration/Support/postop_discharge_child.php', (string) $case->id, (string) $world->users['doctor']->id, (string) $world->entity->id, $go];

    try {
        $results = spiRaceChildren([$command, $command], $go);
    } finally {
        @unlink($go);
    }

    foreach ($results as $result) {
        expect($result->successful())->toBeTrue("alta falhou — stderr: {$result->errorOutput()}")
            ->and(trim($result->output()))->toBe('discharged');
    }

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Discharged)
        ->and(SurgeryDocumentIssue::query()->withoutGlobalScopes()->where('surgical_case_id', $case->id)->where('type', 'discharge')->count())->toBe(1)
        ->and(Schedule::query()->withoutGlobalScopes()->where('surgical_case_id', $case->id)->where('situation', 9)->count())->toBe(3)
        ->and(collect($case->status_history)->where('to', 'discharged'))->toHaveCount(1);
});
