<?php

declare(strict_types=1);

/**
 * Atendido × agendamento remarcado por OUTRA sessão, com duas conexões
 * PostgreSQL de verdade — achado via revisão adversarial Codex: changeSituation
 * tomava o lock paciente/dia e reconciliava as capturas órfãs com a
 * identidade (paciente/dia/médico) da instância carregada no início da
 * requisição; refreshSituationUnderLock() relia só situação e chegada. Remarcado
 * nesse intervalo, o agendamento virava Atendido mas as capturas do dia
 * NOVO ficavam sem vínculo e o repasse contava agendamento de exame + captura
 * (2 atos).
 *
 * Agora a identidade é relida com a linha travada; se a chave do lock mudou, a
 * tentativa é desfeita (SAVEPOINT solta linha e lock) e refeita com o lock do
 * dia certo, antes de qualquer linha. Aqui: a outra sessão remarca (a) no
 * instante exato entre o lock paciente/dia e o da linha (gancho em DB::listen)
 * e (b) segurando a trava da linha enquanto a principal espera (processo
 * filho); e o caso de nunca estabilizar (desiste após 3 tentativas, sem gravar
 * nada). Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda
 * por caminho explícito.
 */

use App\Enums\{ExamSource, ScheduleSituation};
use App\Exceptions\ScheduleChangedConcurrentlyException;
use App\Models\{Covenant, Entity, ExamType, Patient, PatientExam, Procedure, Schedule, ScheduleSituationLog, VisitType};
use App\Services\Financial\DoctorPayouts\DoctorPayoutProductionService;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

const AIR_PROBE_CONNECTION = 'pgsql_probe_attendance_identity';

/** Segunda sessão PostgreSQL real: o que ela grava já está comitado. */
function airProbe(): Connection
{
    config(['database.connections.' . AIR_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    $probe = DB::connection(AIR_PROBE_CONNECTION);

    // Os ganchos de DB::listen rodam síncronos na thread da principal: se a
    // sonda esperar uma trava que a PRÓPRIA principal segura, ninguém acorda.
    // Com lock_timeout o teste falha em segundos em vez de pendurar a suíte.
    $probe->statement("SET lock_timeout = '3s'");

    return $probe;
}

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function airChildEnv(): array
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
 * Agendamento de EXAME Aguardando no dia X + captura pela câmera do mesmo
 * paciente/médico no dia Y (órfã). Remarcar o agendamento pro dia Y é o que
 * faz a captura ser dele.
 *
 * @return array{entity: Entity, doctor: object, schedule: Schedule, capture: PatientExam}
 */
function airScenario(): array
{
    Storage::fake('s3');

    $entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $covenant = Covenant::factory()->create(['entity_id' => $entity->id, 'active' => true, 'name' => 'OPERADORA TESTE', 'ans_registry' => '326305']);
    $patient  = Patient::factory()->create(['entity_id' => $entity->id, 'covenant_id' => $covenant->id]);
    $doctor   = createDoctorForEntity($entity);
    $visit    = VisitType::create([
        'entity_id'    => $entity->id, 'name' => 'EXAME', 'active' => true,
        'procedure_id' => Procedure::factory()->create(['treatment' => 3, 'name' => 'RETINOGRAFIA'])->id,
    ]);

    $schedule = Schedule::create([
        'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'visit_id' => $visit->id,
        'full_name' => 'Paciente Identidade', 'date_time' => '2026-06-09 09:00:00',
        'situation' => ScheduleSituation::Waiting->value, 'active' => true,
    ]);

    $capture = PatientExam::factory()->create([
        'patient_id'        => $patient->id,
        'exam_id'           => ExamType::factory()->create(['entity_id' => null])->id,
        'doctor_id'         => $doctor->id,
        'schedule_id'       => null,
        'source'            => ExamSource::BrowserCapture->value,
        'exam_performed_at' => '2026-06-10 11:00:00',
        'active'            => true,
    ]);

    return compact('entity', 'doctor', 'schedule', 'capture');
}

function airPayoutActs(Entity $entity, object $doctor): int
{
    return app(DoctorPayoutProductionService::class)
        ->acts($entity->id, $doctor->id, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'))
        ->count();
}

beforeEach(function () {
    airProbe();
});

afterEach(function () {
    DB::purge(AIR_PROBE_CONNECTION);
});

it('[GAP] agendamento remarcado por OUTRA sessão entre o lock paciente/dia e o lock da linha: reinicia com o dia certo, vincula a captura e o repasse conta 1 ato (achado via revisão adversarial Codex)', function () {
    ['entity' => $entity, 'doctor' => $doctor, 'schedule' => $schedule, 'capture' => $capture] = airScenario();
    $stale                                                                                     = Schedule::query()->findOrFail($schedule->id); // route binding: dia X

    // A outra sessão remarca pro dia Y (e comita) logo DEPOIS do lock paciente/dia
    // da 1ª tentativa e ANTES de a principal travar a linha.
    $rescheduled = 0;
    $probe       = airProbe();
    DB::listen(function (QueryExecuted $query) use (&$rescheduled, $probe, $schedule): void {
        if ($rescheduled === 0 && str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
            $rescheduled++;
            $probe->table('schedules')->where('id', $schedule->id)->update(['date_time' => '2026-06-10 09:00:00']);
        }
    });

    app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null);

    expect($rescheduled)->toBe(1)
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
        // O dia novo gravado pela outra sessão NÃO foi sobrescrito pela instância velha.
        ->and($schedule->fresh()->date_time->toDateString())->toBe('2026-06-10')
        ->and($capture->fresh()->schedule_id)->toBe($schedule->id)
        ->and(airPayoutActs($entity, $doctor))->toBe(1)
        ->and(ScheduleSituationLog::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
});

it('[GAP] remarcação em voo (a outra sessão ainda segura a linha): a principal espera, relê a identidade nova, reinicia e reconcilia — captura vinculada e repasse sem duplicar', function () {
    ['entity' => $entity, 'doctor' => $doctor, 'schedule' => $schedule, 'capture' => $capture] = airScenario();
    $stale                                                                                     = Schedule::query()->findOrFail($schedule->id); // route binding: dia X

    $marker = sys_get_temp_dir() . '/air-' . Str::uuid();

    // Filho = "outra requisição": remarca pro dia Y e segura a linha por 2,5 s.
    $child = Process::path(base_path())
        ->env(airChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/schedule_reschedule_child.php', (string) $schedule->id, '2026-06-10 09:00:00', $marker, '2.5']);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a travar — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $startedAt = microtime(true);

        app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null);

        $waited      = microtime(true) - $startedAt;
        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // Esperou a outra sessão comitar (o filho segura 2,5 s)...
        ->and($waited)->toBeGreaterThan(1.0)
        // ...e reconciliou com o dia NOVO.
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
        ->and($schedule->fresh()->date_time->toDateString())->toBe('2026-06-10')
        ->and($capture->fresh()->schedule_id)->toBe($schedule->id)
        ->and(airPayoutActs($entity, $doctor))->toBe(1);
});

it('identidade que nunca estabiliza: desiste após 3 tentativas com a exceção traduzida e SEM gravar nada (situação, log e capturas intactos)', function () {
    ['schedule' => $schedule, 'capture' => $capture] = airScenario();
    $stale                                           = Schedule::query()->findOrFail($schedule->id);

    // A outra sessão remarca de novo a CADA tentativa (um dia diferente sempre).
    $flips = 0;
    $probe = airProbe();
    DB::listen(function (QueryExecuted $query) use (&$flips, $probe, $schedule): void {
        if (str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
            $flips++;
            $probe->table('schedules')->where('id', $schedule->id)->update(['date_time' => sprintf('2026-07-%02d 09:00:00', 10 + $flips)]);
        }
    });

    expect(fn () => app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null))
        ->toThrow(ScheduleChangedConcurrentlyException::class, __('schedules.changed_concurrently'));

    expect($flips)->toBe(3)
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Waiting)
        ->and(ScheduleSituationLog::query()->count())->toBe(0)
        ->and($capture->fresh()->schedule_id)->toBeNull();
});

it('[GAP] reinício com o lock do dia NOVO nas mãos de outra sessão não deadlocka: a principal solta a linha ANTES de esperar (importação de captura no dia novo termina e a principal conclui)', function () {
    ['entity' => $entity, 'doctor' => $doctor, 'schedule' => $schedule, 'capture' => $capture] = airScenario();
    $stale                                                                                     = Schedule::query()->findOrFail($schedule->id); // route binding: dia X

    // Remarcado pro dia Y e comitado ANTES de a principal começar (a instância é que está velha).
    DB::table('schedules')->where('id', $schedule->id)->update(['date_time' => '2026-06-10 09:00:00']);

    $marker = sys_get_temp_dir() . '/air-day-' . Str::uuid();

    // Filho = importação de captura no dia Y: lock paciente/dia primeiro, linha depois.
    $child = Process::path(base_path())
        ->env(airChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/schedule_day_lock_child.php', (string) $entity->id, (string) $schedule->patient_id, '2026-06-10', (string) $schedule->id, $marker, '2.5']);

    $error = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a travar o dia — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        DB::statement("set statement_timeout = '20s'");

        // 1ª tentativa trava a linha e vê a identidade nova; só soltando a linha
        // a principal pode esperar o lock do dia Y sem travar o filho (que, ao
        // acordar, quer a MESMA linha).
        try {
            app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null);
        } catch (Throwable $e) {
            $error = $e;
        }

        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    // Deadlock (40P01) derrubaria um dos dois lados.
    expect($error?->getMessage())->toBeNull()
        ->and($childResult->errorOutput())->not->toContain('40P01')
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
        ->and($capture->fresh()->schedule_id)->toBe($schedule->id)
        ->and(airPayoutActs($entity, $doctor))->toBe(1);
});
