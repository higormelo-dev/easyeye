<?php

declare(strict_types=1);

/**
 * Curso de injeções intravítreas × duas sessões PostgreSQL de verdade (ADR 0002, item 14): dois pedidos para o mesmo
 * olho, a execução de uma aplicação × encerrar o curso, duas execuções ao mesmo tempo (olhos diferentes) e a execução
 * × agendar a próxima aplicação, nos DOIS sentidos (a ordem das travas de linha): o filho agenda e o principal executa, e o
 * filho executa (pausado no meio) e o principal agenda.
 *
 * Em cada cenário o processo FILHO faz a operação e SEGURA a transação aberta alguns segundos (com as travas do
 * prontuário, do procedimento e do paciente tomadas); o principal tenta a operação concorrente nessa janela. A operação
 * tem que ESPERAR a trava e, depois do commit do outro, decidir pelo estado COMITADO: nunca dois cursos abertos no mesmo
 * olho, nunca aplicação executada num curso que acabou de ser encerrado, nunca deadlock entre as ordens de trava.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito; depois:
 * php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, CourseStatus};
use App\Domains\Recall\Intravitreal\Exceptions\IntravitrealException;
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Intravitreal\Services\{IntravitrealApplicationService, IntravitrealCourseService};
use App\Enums\{MedicalRecordProcedureStatus, ScheduleSituation};
use App\Models\{DoctorWorkSchedule, MedicalRecord, MedicalRecordProcedure, Procedure, Schedule};
use App\Services\MedicalRecordProcedureExecutionService;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

function ivcChildEnv(array $extra = []): array
{
    $db = config('database.connections.' . config('database.default'));

    return $extra + [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER'      => 'array',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

/**
 * @return array{0: float, 1: ?Throwable, 2: ProcessResult}
 */
function ivcRace(string $mode, string $id, callable $mainOperation, array $env = [], float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/ivc-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(ivcChildEnv($env))
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/ivt_child.php', $mode, $id, $marker, (string) $hold]);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        DB::statement("set statement_timeout = '30s'");

        $startedAt = microtime(true);
        $thrown    = null;

        try {
            $mainOperation();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        return [microtime(true) - $startedAt, $thrown, $child->wait()];
    } finally {
        @unlink($marker);
    }
}

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->patient = rcfPatient($this->entity);
    $this->record  = MedicalRecord::query()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id]);
    $this->ivt     = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'IVT-C', 'name' => 'Injeção intravítrea', 'clinical_kind' => Procedure::KIND_INTRAVITREAL, 'active' => true]);
    $this->eu      = $this->doctor->entityUser;
    $this->env     = ['IVT_ENTITY' => (string) $this->entity->id, 'IVT_EU' => (string) $this->eu->id];
    $this->courses = app(IntravitrealCourseService::class);
});

function ivcProcedure(object $test, string $eye): MedicalRecordProcedure
{
    return MedicalRecordProcedure::create([
        'entity_id'    => $test->entity->id, 'patient_id' => $test->patient->id, 'medical_record_id' => $test->record->id,
        'procedure_id' => $test->ivt->id, 'doctor_id' => $test->doctor->id, 'eye' => $eye,
    ]);
}

function ivcExecution(): array
{
    return ['lot_number' => 'LOT-PRINCIPAL', 'lot_expiry' => now()->addYear()->toDateString(), 'next_interval_weeks' => 4];
}

it('[CORRIDA] dois pedidos no mesmo olho ao mesmo tempo: o 2º espera a trava, enxerga o curso comitado e o reaproveita — um curso só', function () {
    $first  = ivcProcedure($this, 'right');
    $second = ivcProcedure($this, 'right');

    [$waited, $thrown, $child] = ivcRace(
        'ensure',
        (string) $first->id,
        fn () => $this->courses->ensureForProcedure($second, 'right', ['drug_key' => 'ranibizumab']),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        ->and($waited)->toBeGreaterThan(1.0)
        ->and(IntravitrealCourse::query()->withoutGlobalScopes()->count())->toBe(1)
        // A droga é a do 1º pedido (o que ganhou a corrida): o 2º só continuou o curso.
        ->and(IntravitrealCourse::query()->withoutGlobalScopes()->first()->drug_key)->toBe('aflibercept')
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->orderBy('sequence_no')->pluck('sequence_no')->all())->toBe([1, 2]);
});

it('[CORRIDA] execução de uma aplicação × encerrar o curso: o encerramento espera, vê a aplicação comitada e cancela só a PRÓXIMA planejada', function () {
    $procedure = ivcProcedure($this, 'right');
    $this->courses->ensureForProcedure($procedure, 'right', ['drug_key' => 'aflibercept']);
    $course = IntravitrealCourse::query()->withoutGlobalScopes()->firstOrFail();

    [$waited, $thrown, $child] = ivcRace(
        'execute',
        (string) $procedure->id,
        fn () => $this->courses->close((string) $this->entity->id, (string) $course->id, CourseStatus::Discontinued, 'Paciente mudou de cidade'),
        $this->env,
    );

    $statuses = IntravitrealApplication::query()->withoutGlobalScopes()->orderBy('sequence_no')->pluck('status')->map(fn ($s) => $s->value)->all();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($course->refresh()->status)->toBe(CourseStatus::Discontinued)
        ->and($statuses)->toBe([ApplicationStatus::Performed->value, ApplicationStatus::Cancelled->value])
        ->and(MedicalRecordProcedure::query()->find($procedure->id)->status)->toBe(MedicalRecordProcedureStatus::Done);
});

it('[CORRIDA] encerrar o curso × execução da aplicação: a execução espera, vê o curso encerrado e é recusada sem registrar nada', function () {
    $procedure = ivcProcedure($this, 'right');
    $this->courses->ensureForProcedure($procedure, 'right', ['drug_key' => 'aflibercept']);
    $course = IntravitrealCourse::query()->withoutGlobalScopes()->firstOrFail();

    [$waited, $thrown, $child] = ivcRace(
        'close',
        (string) $course->id,
        fn () => app(MedicalRecordProcedureExecutionService::class)->markDone($procedure, $this->eu, [], null, ivcExecution()),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(IntravitrealException::class)
        ->and($course->refresh()->status)->toBe(CourseStatus::Discontinued)
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->count())->toBe(0)
        ->and(MedicalRecordProcedure::query()->find($procedure->id)->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('[SEM DEADLOCK] duas execuções de olhos diferentes do mesmo paciente ao mesmo tempo: as duas comitam, cada olho no seu curso', function () {
    $right = ivcProcedure($this, 'right');
    $left  = ivcProcedure($this, 'left');

    $this->courses->ensureForProcedure($right, 'right', ['drug_key' => 'aflibercept']);
    $this->courses->ensureForProcedure($left, 'left', ['drug_key' => 'bevacizumab']);

    [$waited, $thrown, $child] = ivcRace(
        'execute',
        (string) $right->id,
        fn () => app(MedicalRecordProcedureExecutionService::class)->markDone($left, $this->eu, [], null, ivcExecution()),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->count())->toBe(2)
        ->and(IntravitrealCourse::query()->withoutGlobalScopes()->where('status', CourseStatus::Active->value)->whereNotNull('next_due_on')->count())->toBe(2)
        ->and(MedicalRecordProcedure::query()->where('status', MedicalRecordProcedureStatus::Done->value)->count())->toBe(2);
});

it('[SEM DEADLOCK] agendar a próxima aplicação × execução dessa aplicação: o agendamento espera a trava do paciente e conclui sobre o estado comitado (achado via revisão adversarial Codex)', function () {
    // Médico com expediente e o atendimento de referência (a série aberta nasce dele).
    for ($d = 1; $d <= 6; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $attendance = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'full_name' => 'PACIENTE IVT',
        'date_time' => CarbonImmutable::now()->subDays(2)->setTime(9, 0), 'situation' => ScheduleSituation::Attended->value, 'active' => true,
    ]);
    $this->record->forceFill(['schedule_id' => $attendance->id])->saveQuietly();

    $procedure = ivcProcedure($this, 'right');
    $this->courses->ensureForProcedure($procedure, 'right', ['drug_key' => 'aflibercept']);
    $course = IntravitrealCourse::query()->withoutGlobalScopes()->firstOrFail();
    $when   = CarbonImmutable::parse(Carbon::now()->next(Carbon::MONDAY)->addWeeks(3)->setTime(9, 0));
    $result = null;

    // O filho é a execução de verdade, pausada DEPOIS de travar a aplicação (paciente → aplicação) e ANTES do curso. O
    // agendamento que travava o curso primeiro e só então a aplicação ficava no meio do caminho: cada lado segurava o
    // que o outro queria (40P01) — o PostgreSQL abortava um deles (o filho, que pedia o curso por último).
    [$waited, $thrown, $child] = ivcRace(
        'execute_paused',
        (string) $procedure->id,
        function () use ($course, $when, &$result): void {
            $result = app(IntravitrealApplicationService::class)->scheduleNext((string) $this->entity->id, (string) $course->id, $when);
        },
        $this->env,
    );

    $planned = IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Planned->value)->get();

    expect($child->errorOutput())->not->toContain('40P01')
        ->and($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        // O agendamento ESPEROU a trava do paciente (o filho a segura 2,5 s) e só depois leu o estado comitado.
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($result['ok'])->toBeTrue('scheduleNext recusou: ' . json_encode($result))
        // A aplicação 1 foi executada pelo filho; a que ficou agendada é a PRÓXIMA (a 2), não a que acabou de ser feita.
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->pluck('sequence_no')->all())->toBe([1])
        ->and($planned)->toHaveCount(1)
        ->and($planned->first()->sequence_no)->toBe(2)
        ->and((string) $planned->first()->schedule_id)->toBe((string) $result['schedule_id'])
        ->and(MedicalRecordProcedure::query()->find($procedure->id)->status)->toBe(MedicalRecordProcedureStatus::Done);
});

it('[SEM DEADLOCK] agendar a próxima × executar a MESMA aplicação: a execução espera a trava do paciente e as duas terminam, nenhuma é abortada', function () {
    $procedure = ivcProcedure($this, 'right');
    $this->courses->ensureForProcedure($procedure, 'right', ['drug_key' => 'aflibercept']);
    $course = IntravitrealCourse::query()->withoutGlobalScopes()->firstOrFail();

    // O filho agenda a próxima e SEGURA a transação depois de travar o curso; o principal executa a aplicação nessa
    // janela (paciente → aplicação → curso). Se o agendar travasse o curso e DEPOIS a aplicação sem a trava do
    // paciente, cada um seguraria a linha de que o outro precisa e o PostgreSQL abortaria um deles (40P01).
    [$waited, $thrown, $child] = ivcRace(
        'schedule_next',
        (string) $course->id,
        fn () => app(MedicalRecordProcedureExecutionService::class)->markDone($procedure, $this->eu, [], null, ivcExecution()),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        // A execução ficou esperando o filho (a trava do paciente), em vez de seguir até a linha do curso.
        ->and($waited)->toBeGreaterThan(1.0)
        // Sem atendimento de referência o agendar recusa e desfaz; o que importa é que a execução comitou inteira.
        ->and($child->output())->toContain('"ok":false')
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->count())->toBe(1)
        ->and(MedicalRecordProcedure::query()->find($procedure->id)->status)->toBe(MedicalRecordProcedureStatus::Done);
});
