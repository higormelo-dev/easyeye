<?php

declare(strict_types=1);

/**
 * Encaixe já Atendido: criação × edição simultâneas, com duas conexões
 * PostgreSQL de verdade — achado via revisão adversarial Codex. O cadastro toma
 * a cota de encaixes do médico/turno e depois o lock paciente/dia; a edição
 * (PUT) tomava o lock paciente/dia ANTES da cota. Criação e edição do mesmo
 * paciente/dia e do mesmo médico/turno ao mesmo tempo: cada uma segurava um dos
 * dois locks e esperava o outro (deadlock) — o PostgreSQL abortava uma delas e
 * uma ação válida virava erro.
 *
 * O processo filho faz os dois primeiros locks do cadastro, na ordem do
 * sistema, e segura a cota; o principal faz a edição de verdade (HTTP) por
 * cima. Na ordem nova (cota primeiro) a edição espera o filho terminar e
 * conclui; na antiga os dois travavam. A ordem exata do cadastro e da edição
 * está em tests/Feature/Schedules/FitInLockOrderTest.php. Fica em
 * tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho
 * explícito.
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, DoctorWorkSchedule, Entity, Patient, Schedule, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function filiChildEnv(): array
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

it('[GAP] criação (cota → paciente/dia) e edição de encaixe Atendido ao mesmo tempo não deadlockam: a edição espera a cota e conclui (achado via revisão adversarial Codex)', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor  = createDoctorForEntity($entity);
    $patient = Patient::factory()->create(['entity_id' => $entity->id, 'covenant_id' => Covenant::factory()->create(['entity_id' => $entity->id, 'active' => true])->id]);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15]);

    $secretary = createEntityUser($entity, User::factory()->create(), ClientRule::Secretary->value);
    $monday    = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);

    // Encaixe já marcado (Aguardando) — a edição vai levá-lo a Atendido.
    $schedule = Schedule::create([
        'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'full_name' => 'PACIENTE ENCAIXE', 'date_time' => $monday, 'situation' => ScheduleSituation::Waiting->value,
        'is_fit_in' => true, 'active' => true,
    ]);

    $marker = sys_get_temp_dir() . '/fili-' . Str::uuid();

    // Filho = cadastro de outro encaixe do mesmo paciente/dia e médico/turno: segura a cota 2,5 s.
    $child = Process::path(base_path())
        ->env(filiChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/fit_in_store_locks_child.php', (string) $doctor->id, (string) $entity->id, (string) $patient->id, $monday->format('Y-m-d H:i'), $marker, '2.5']);

    $status = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a travar a cota — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $startedAt = microtime(true);

        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->putJson(route('panel.schedules.update', $schedule), [
                'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'full_name' => 'PACIENTE ENCAIXE',
                'date_time' => $monday->format('Y-m-d H:i'), 'is_fit_in' => true,
                'situation' => ScheduleSituation::Attended->value, 'notes' => 'chegou',
            ]);

        $waited      = microtime(true) - $startedAt;
        $status      = $response->getStatusCode();
        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    // Deadlock (40P01) derrubaria um dos dois lados: a edição viraria 500 ou o filho falharia.
    expect($status)->toBe(200)
        ->and($childResult->errorOutput())->not->toContain('40P01')
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // A edição esperou a cota do filho (o filho a segura 2,5 s) e depois concluiu.
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
});
