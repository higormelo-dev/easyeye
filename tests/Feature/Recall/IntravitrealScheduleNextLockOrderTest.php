<?php

declare(strict_types=1);

/**
 * Agendar a próxima aplicação do curso × a ordem de travas (achado via revisão adversarial Codex). Todo caminho que
 * escreve o curso ou a aplicação toma ANTES de qualquer linha a trava do PACIENTE do recall (RecallLocks::patient — é
 * ela que serializa `recordExecution`, `ensureForProcedure`, `close` e `releaseProcedure`); o agendamento da próxima
 * (`scheduleNext`) não tomava: travava o curso e depois a aplicação enquanto a execução travava a aplicação e depois o
 * curso — cada lado segurava o que o outro queria (deadlock 40P01 → 500 e, no pior caso, a baixa da injeção desfeita).
 *
 * A ordem das instruções é checada aqui (determinística); a corrida com duas conexões reais está em
 * tests/Integration/IntravitrealConcurrencyTest.php. Ordem única, a mesma de `store()` da Agenda:
 * cota do encaixe → paciente do recall → curso → aplicação → trava da série → INSERT da sessão.
 */

use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, CourseStatus};
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Intravitreal\Services\{IntravitrealApplicationService, IntravitrealCourseService};
use App\Enums\ScheduleSituation;
use App\Models\{DoctorWorkSchedule, MedicalRecord, MedicalRecordProcedure, Procedure, Schedule};
use App\Services\{MedicalRecordProcedureExecutionService, ScheduleSeriesService, ScheduleService};
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    for ($d = 1; $d <= 6; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15, 'fit_in_limit_morning' => 5]);

    $this->patient = rcfPatient($this->entity);
    $this->record  = MedicalRecord::query()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id]);
    $this->ivt     = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'IVT-L', 'name' => 'Injeção intravítrea', 'clinical_kind' => Procedure::KIND_INTRAVITREAL, 'active' => true]);
});

/** O atendimento de hoje (referência da série) ligado ao prontuário, a injeção pedida e executada: sobra a próxima planejada. */
function isnCourse(object $test, array $attendance = []): IntravitrealCourse
{
    $schedule = Schedule::create(array_merge([
        'entity_id' => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id, 'full_name' => 'PACIENTE IVT',
        'date_time' => CarbonImmutable::parse('2026-03-10 09:00:00'), 'situation' => ScheduleSituation::Attended->value, 'active' => true,
    ], $attendance));

    $test->record->forceFill(['schedule_id' => $schedule->id])->saveQuietly();

    $procedure = MedicalRecordProcedure::create([
        'entity_id'    => $test->entity->id, 'patient_id' => $test->patient->id, 'medical_record_id' => $test->record->id,
        'procedure_id' => $test->ivt->id, 'doctor_id' => $test->doctor->id, 'eye' => 'right',
    ]);

    app(IntravitrealCourseService::class)->ensureForProcedure($procedure, 'right', ['drug_key' => 'aflibercept']);
    app(MedicalRecordProcedureExecutionService::class)->markDone(
        $procedure,
        $test->doctor->entityUser,
        [],
        null,
        ['lot_number' => 'LOT-ORDEM', 'lot_expiry' => '2027-01-31', 'next_interval_weeks' => 8],
    );

    return IntravitrealCourse::query()->withoutGlobalScopes()->sole();
}

/**
 * Roda `scheduleNext` e devolve as instruções de TRAVA na ordem em que saíram: `quota` (cota do encaixe), `recall`
 * (paciente), `course`, `application`, `series` e `insert` (a sessão), para a comparação por posição. O ouvinte só
 * guarda o que viu; as chaves das travas por advisory são traduzidas depois (a trava da série só existe após a série).
 *
 * @return array{result: array<string, mixed>, order: list<string>}
 */
function isnScheduleNext(object $test, IntravitrealCourse $course, string $at): array
{
    $seen = [];

    DB::listen(function ($query) use (&$seen): void {
        $sql = $query->sql;

        if (str_contains($sql, 'pg_advisory_xact_lock(hashtext(?), hashtext(?))') && ($query->bindings[0] ?? null) === 'easyeye.recall.patient') {
            $seen[] = ['recall', (string) $query->bindings[1]];
        } elseif (str_contains($sql, 'pg_advisory_xact_lock(?)')) {
            $seen[] = ['advisory', (int) $query->bindings[0]];
        } elseif (str_contains($sql, 'for update') && str_contains($sql, 'from "intravitreal_courses"')) {
            $seen[] = ['course', null];
        } elseif (str_contains($sql, 'for update') && str_contains($sql, 'from "intravitreal_applications"')) {
            $seen[] = ['application', null];
        } elseif (str_starts_with($sql, 'insert into "schedules"')) {
            $seen[] = ['insert', null];
        }
    });

    $result = app(IntravitrealApplicationService::class)->scheduleNext((string) $test->entity->id, (string) $course->id, CarbonImmutable::parse($at));

    $quota  = ScheduleService::fitInLockKey((string) $test->doctor->id, Carbon::parse($at));
    $series = Schedule::query()->withoutGlobalScopes()->where('patient_id', $test->patient->id)->whereNotNull('series_id')->value('series_id');
    $series = $series !== null ? ScheduleSeriesService::seriesLockKey((string) $test->entity->id, (string) $series) : null;

    $order = array_map(static fn (array $event): string => match (true) {
        $event[0] === 'recall'                            => $event[1] === (string) $test->patient->id ? 'recall' : 'recall:outro-paciente',
        $event[0] === 'advisory' && $event[1] === $quota  => 'quota',
        $event[0] === 'advisory' && $event[1] === $series => 'series',
        $event[0] === 'advisory'                          => 'advisory:outro',
        default                                           => $event[0],
    }, $seen);

    return ['result' => $result, 'order' => $order];
}

/** Posição da 1ª ocorrência (ou nulo): a comparação de ordem é sempre entre posições que existem. */
function isnAt(array $order, string $event): ?int
{
    $position = array_search($event, $order, true);

    return $position === false ? null : $position;
}

it('agendar a próxima toma a trava do paciente ANTES de qualquer linha do curso ou da aplicação (a ordem de recordExecution)', function () {
    $course = isnCourse($this);

    ['result' => $result, 'order' => $order] = isnScheduleNext($this, $course, '2026-05-05 09:00');

    expect($result['ok'])->toBeTrue()
        ->and(isnAt($order, 'recall'))->toBeInt()
        ->and(isnAt($order, 'course'))->toBeInt()
        ->and(isnAt($order, 'application'))->toBeInt()
        ->and(isnAt($order, 'recall'))->toBeLessThan(isnAt($order, 'course'))
        ->and(isnAt($order, 'course'))->toBeLessThan(isnAt($order, 'application'))
        // A trava da série e o INSERT da sessão vêm depois das linhas do curso (a mesma ordem de store()).
        ->and(isnAt($order, 'application'))->toBeLessThan(isnAt($order, 'series'))
        ->and(isnAt($order, 'series'))->toBeLessThan(isnAt($order, 'insert'))
        // Uma trava de paciente só, e é a do paciente do curso.
        ->and(array_keys($order, 'recall', true))->toHaveCount(1)
        ->and($order)->not->toContain('recall:outro-paciente')
        // Sessão comum não tem cota de encaixe: nada a travar antes do paciente.
        ->and($order)->not->toContain('quota');
});

it('encaixe: a cota do turno vem ANTES da trava do paciente — a ordem de store() (cota → paciente do recall), sem inversão com o agendamento de retorno', function () {
    $course = isnCourse($this, ['is_fit_in' => true]);

    ['result' => $result, 'order' => $order] = isnScheduleNext($this, $course, '2026-05-05 09:00');

    expect($result['ok'])->toBeTrue()
        ->and(isnAt($order, 'quota'))->toBeInt()
        ->and(isnAt($order, 'recall'))->toBeInt()
        ->and(isnAt($order, 'quota'))->toBeLessThan(isnAt($order, 'recall'))
        ->and(isnAt($order, 'recall'))->toBeLessThan(isnAt($order, 'course'))
        ->and(isnAt($order, 'recall'))->toBeLessThan(isnAt($order, 'application'));
});

it('a trava vale também quando o agendamento é recusado (curso encerrado): quem recusa já está na fila do paciente', function () {
    $course = isnCourse($this);

    app(IntravitrealCourseService::class)->close((string) $this->entity->id, (string) $course->id, CourseStatus::Completed, 'Tratamento concluído');

    ['result' => $result, 'order' => $order] = isnScheduleNext($this, $course, '2026-05-05 09:00');

    expect($result['ok'])->toBeFalse()
        ->and(isnAt($order, 'recall'))->toBeInt()
        ->and(isnAt($order, 'recall'))->toBeLessThan(isnAt($order, 'course'))
        ->and(isnAt($order, 'insert'))->toBeNull();
});

it('curso de outra clínica ou inexistente: recusado e nada gravado', function () {
    $course = isnCourse($this);
    $other  = rcfClinic(recall: true);

    $foreign = app(IntravitrealApplicationService::class)->scheduleNext((string) $other['entity']->id, (string) $course->id, CarbonImmutable::parse('2026-05-05 09:00'));
    $missing = app(IntravitrealApplicationService::class)->scheduleNext((string) $this->entity->id, '00000000-0000-4000-8000-000000000000', CarbonImmutable::parse('2026-05-05 09:00'));

    expect($foreign['ok'])->toBeFalse()
        ->and($missing['ok'])->toBeFalse()
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Planned->value)->whereNotNull('schedule_id')->count())->toBe(0)
        ->and(Schedule::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1);
});
