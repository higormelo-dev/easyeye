<?php

declare(strict_types=1);

/**
 * Ordem dos locks da reconciliação "paciente atendido neste dia" — achado via
 * revisão adversarial Codex: a transição pra Atendido trava a linha do PRÓPRIO
 * agendamento (refreshSituationUnderLock) e, na reconciliação de capturas
 * órfãs, TODOS os agendamentos do paciente naquele dia; a importação de
 * captura trava os mesmos candidatos. Sem uma ordem comum, duas transições
 * simultâneas (cada uma segurando a sua linha) esperavam uma a outra:
 * deadlock. Regra agora: o lock paciente/dia (Schedule::lockPatientDay) vem
 * ANTES de qualquer linha de agendamento — em changeSituation(), na
 * importação e no formulário da agenda (que dá UPDATE antes de aplicar a
 * situação).
 *
 * Aqui, a ORDEM dos comandos SQL. O deadlock em si, com 2 processos e 2
 * agendamentos de verdade, está em
 * tests/Integration/AttendanceReconciliationDeadlockTest.php.
 */

use App\Enums\{ClientRule, ExamSource, ScheduleSituation};
use App\Models\{DoctorWorkSchedule, Entity, ExamType, Patient, Schedule, User};
use App\Services\{ExternalExamImportService, ScheduleService};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};

beforeEach(function () {
    Storage::fake('s3');

    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->examType = ExamType::factory()->create(['entity_id' => null]);
    $this->day      = now()->subDay()->setTime(9, 0);
});

/** @return list<array{sql: string, bindings: array<int, mixed>}> */
function arloQueries(Closure $action): array
{
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
    });

    $action();

    return $log;
}

/** Posição do lock paciente/dia (advisory) com a chave esperada, ou null. */
function arloPatientDayLock(array $log, int $key): ?int
{
    foreach ($log as $position => $query) {
        if (str_contains($query['sql'], 'pg_advisory_xact_lock') && ($query['bindings'][0] ?? null) === $key) {
            return $position;
        }
    }

    return null;
}

/** Primeira instrução que trava ou escreve uma linha de agendamento. */
function arloFirstScheduleRowStatement(array $log): ?int
{
    foreach ($log as $position => $query) {
        $locksRow  = str_contains($query['sql'], 'for update') && str_contains($query['sql'], 'schedules');
        $writesRow = str_starts_with($query['sql'], 'update "schedules"');

        if ($locksRow || $writesRow) {
            return $position;
        }
    }

    return null;
}

function arloKey(object $test): int
{
    return Schedule::patientDayLockKey((string) $test->entity->id, (string) $test->patient->id, $test->day);
}

it('[GAP] transição pra Atendido toma o lock paciente/dia ANTES de travar a linha do agendamento — a ordem que evita o deadlock entre duas transições (achado via revisão adversarial Codex)', function () {
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id, 'situation' => ScheduleSituation::Waiting->value, 'date_time' => $this->day,
    ]);
    createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id, 'situation' => ScheduleSituation::Waiting->value, 'date_time' => $this->day->copy()->addHour(),
    ]);

    $log = arloQueries(fn () => app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::Attended, null));

    $lock = arloPatientDayLock($log, arloKey($this));
    $row  = arloFirstScheduleRowStatement($log);

    expect($lock)->not->toBeNull()
        ->and($row)->not->toBeNull()
        ->and($lock)->toBeLessThan($row);
});

it('transição que NÃO leva a Atendido não toma o lock paciente/dia — não serializa o que não reconcilia nada', function () {
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id, 'situation' => ScheduleSituation::Scheduled->value, 'date_time' => $this->day,
    ]);

    $log = arloQueries(fn () => app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::Waiting, null));

    expect(arloPatientDayLock($log, arloKey($this)))->toBeNull();
});

it('[GAP] importação de captura pela câmera toma o MESMO lock paciente/dia antes de travar os agendamentos candidatos', function () {
    ['doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id, 'situation' => ScheduleSituation::Waiting->value, 'date_time' => $this->day,
    ]);

    $log = arloQueries(fn () => app(ExternalExamImportService::class)->import(
        (string) $this->entity->id,
        [
            'patient_id'        => $this->patient->id,
            'exam_id'           => $this->examType->id,
            'exam_performed_at' => $this->day->toDateString(),
            'files_source'      => [ExamSource::BrowserCapture->value],
            'doctor_id'         => $doctor->id,
        ],
        [UploadedFile::fake()->image('captura.jpg')],
        (string) User::factory()->create()->id,
    ));

    $lock = arloPatientDayLock($log, arloKey($this));
    $row  = arloFirstScheduleRowStatement($log);

    expect($lock)->not->toBeNull()
        ->and($row)->not->toBeNull()
        ->and($lock)->toBeLessThan($row);
});

it('[GAP] formulário da agenda com situação Atendido toma o lock paciente/dia ANTES do UPDATE que já trava a linha do agendamento', function () {
    // Médico com expediente na segunda — o PUT valida o horário.
    $doctor = createDoctorForEntity($this->entity);
    $monday = now()->next(Carbon\Carbon::MONDAY)->setTime(9, 0);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15]);

    $schedule = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $doctor->id, 'patient_id' => $this->patient->id,
        'full_name' => 'PACIENTE', 'date_time' => $monday, 'situation' => ScheduleSituation::Waiting->value, 'active' => true,
    ]);

    $secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

    $log = arloQueries(fn () => $this->actingAs($secretary->user)->withSession(panelSession($secretary))
        ->putJson(route('panel.schedules.update', $schedule), [
            'doctor_id' => $doctor->id, 'patient_id' => $this->patient->id, 'full_name' => 'PACIENTE',
            'date_time' => $monday->format('Y-m-d H:i'), 'situation' => ScheduleSituation::Attended->value,
            // Campo alterado de verdade: sem ele o Eloquent nem emite o UPDATE e o teste não prova a ordem.
            'notes' => 'Paciente chegou mais cedo',
        ])->assertOk());

    $lock = arloPatientDayLock($log, Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $monday));
    $row  = arloFirstScheduleRowStatement($log);

    expect($lock)->not->toBeNull()
        ->and($row)->not->toBeNull()
        ->and($lock)->toBeLessThan($row)
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
        ->and($schedule->fresh()->notes)->toBe('Paciente chegou mais cedo');
});
