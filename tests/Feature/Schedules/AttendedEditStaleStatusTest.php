<?php

declare(strict_types=1);

/**
 * PUT da agenda SEM o campo situação, com o agendamento virando Atendido por
 * OUTRA requisição entre o route binding e o UPDATE — achado via revisão
 * adversarial Codex: lockForEdit() decidia "vou reconciliar capturas?" pela
 * situação da instância do binding (Aguardando). Atendido comitado no meio, o
 * PUT não travava paciente/dia nem relia a linha: o UPDATE mudava médico/dia
 * do agendamento que, no banco, já era Atendido, mas a instância continuava
 * Aguardando — reconcileExamLinks() não vinculava as capturas da identidade
 * nova e o repasse contava o agendamento de exame E a captura (2 atos).
 *
 * Agora a situação final (a pedida ou, se omitida, a do BANCO, lida sob a
 * trava da linha) decide; mudou, a tentativa é desfeita e refeita com os locks
 * paciente/dia antes da linha. A corrida real, com duas conexões, está em
 * tests/Integration/AttendedEditStaleStatusTest.php.
 */

use App\Enums\{ClientRule, ExamSource, ScheduleSituation};
use App\Models\{Covenant, DoctorWorkSchedule, Entity, ExamType, Patient, PatientExam, Procedure, Schedule, User, VisitType};
use App\Services\Financial\DoctorPayouts\DoctorPayoutProductionService;
use App\Services\ScheduleService;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\{DB, Storage};

beforeEach(function () {
    Storage::fake('s3');

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $covenant      = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'ans_registry' => '326305']);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $covenant->id]);
    $this->doctor  = createDoctorForEntity($this->entity);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->monday    = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
    $this->tuesday   = $this->monday->copy()->addDay();

    // Agendamento de EXAME (tratamento 3): a captura vinculada não conta de novo no repasse.
    $this->visitType = VisitType::create([
        'entity_id'    => $this->entity->id, 'name' => 'EXAME', 'active' => true,
        'procedure_id' => Procedure::factory()->create(['treatment' => 3, 'name' => 'RETINOGRAFIA'])->id,
    ]);

    // Aguardando na segunda — é o que o route binding vai carregar.
    $this->schedule = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
        'visit_id'  => $this->visitType->id, 'full_name' => 'PACIENTE STATUS', 'date_time' => $this->monday,
        'situation' => ScheduleSituation::Waiting->value, 'active' => true,
    ]);

    // Captura órfã do paciente/médico na terça — é do agendamento depois que ele for pra terça.
    $this->orphan = PatientExam::factory()->create([
        'patient_id'        => $this->patient->id, 'exam_id' => ExamType::factory()->create(['entity_id' => null])->id,
        'doctor_id'         => $this->doctor->id, 'schedule_id' => null, 'source' => ExamSource::BrowserCapture->value,
        'exam_performed_at' => $this->tuesday->copy()->setTime(11, 0), 'active' => true,
    ]);
});

/** Outra requisição comita "Atendido" logo depois do route binding (a 1ª leitura do agendamento por id). */
function asseFlipAfterBinding(object $test): void
{
    $flipped = false;

    DB::listen(function (QueryExecuted $query) use (&$flipped, $test): void {
        $sql = strtolower($query->sql);

        if (! $flipped && str_starts_with($sql, 'select') && str_contains($sql, 'from "schedules"') && ! str_contains($sql, 'for update') && in_array($test->schedule->id, $query->bindings, true)) {
            $flipped = true;
            DB::table('schedules')->where('id', $test->schedule->id)->update(['situation' => ScheduleSituation::Attended->value]);
        }
    });
}

/** Atos do repasse do médico no mês do dia informado. */
function asseActs(object $test, Carbon $day): int
{
    return app(DoctorPayoutProductionService::class)->acts(
        $test->entity->id,
        $test->doctor->id,
        CarbonImmutable::parse($day)->startOfMonth(),
        CarbonImmutable::parse($day)->endOfMonth(),
    )->count();
}

it('[GAP] PUT sem situação: o agendamento vira Atendido por outra requisição depois do binding — as capturas da identidade nova são vinculadas, os locks paciente/dia saem ANTES do UPDATE e o repasse conta UM ato (achado via revisão adversarial Codex)', function () {
    asseFlipAfterBinding($this);

    $origin      = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->monday);
    $destination = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->tuesday);

    $sequence = [];
    DB::listen(function (QueryExecuted $query) use (&$sequence): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $sequence[] = ['advisory', $query->bindings[0] ?? null];
        } elseif (str_starts_with($sql, 'update "schedules"') && str_contains($sql, '"date_time"')) { // o UPDATE da edição (não o do "Atendido" da outra requisição)
            $sequence[] = ['update', null];
        }
    });

    $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->putJson(route('panel.schedules.update', $this->schedule), [
            'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'full_name' => 'PACIENTE STATUS',
            'date_time' => $this->tuesday->copy()->setTime(9, 30)->format('Y-m-d H:i'), // sem o campo "situation"
        ])->assertOk();

    $firstUpdate = array_search(['update', null], $sequence, true);

    expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
        ->and($this->schedule->fresh()->date_time->toDateString())->toBe($this->tuesday->toDateString())
        ->and($this->orphan->fresh()->schedule_id)->toBe($this->schedule->id)
        ->and(asseActs($this, $this->tuesday))->toBe(1)
        // Origem e destino travados ANTES da linha ser escrita (a ordem que evita o deadlock).
        ->and($firstUpdate)->toBeInt()
        ->and(array_search(['advisory', $origin], $sequence, true))->toBeLessThan($firstUpdate)
        ->and(array_search(['advisory', $destination], $sequence, true))->toBeLessThan($firstUpdate);
});

it('PUT sem situação num agendamento que NÃO é Atendido: nenhuma reconciliação, nenhum lock paciente/dia (só a linha)', function () {
    $keys = [];
    DB::listen(function (QueryExecuted $query) use (&$keys): void {
        if (str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
            $keys[] = $query->bindings[0] ?? null;
        }
    });

    $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->putJson(route('panel.schedules.update', $this->schedule), [
            'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'full_name' => 'PACIENTE STATUS',
            'date_time' => $this->tuesday->copy()->setTime(9, 30)->format('Y-m-d H:i'),
        ])->assertOk();

    expect($keys)->toBe([])
        ->and($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Waiting)
        ->and($this->orphan->fresh()->schedule_id)->toBeNull();
});

/**
 * Sequência dos locks advisory (por chave) e das travas de linha do agendamento.
 *
 * @return list<array{0: string, 1: int|string|null}>
 */
function asseLockSequence(Closure $action): array
{
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $log[] = ['advisory', $query->bindings[0] ?? null];
        } elseif (str_contains($sql, 'for update') && str_contains($sql, 'from "schedules"')) {
            $log[] = ['row', null];
        }
    });

    $action();

    return $log;
}

it('[GAP] situação omitida e instância velha (Aguardando) com o banco já Atendido: relê sob a trava, DESFAZ e refaz com os locks paciente/dia ANTES da linha', function () {
    $stale = Schedule::query()->findOrFail($this->schedule->id); // Aguardando
    DB::table('schedules')->where('id', $this->schedule->id)->update(['situation' => ScheduleSituation::Attended->value]);

    $origin      = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->monday);
    $destination = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->tuesday);

    $sequence = asseLockSequence(fn () => app(ScheduleService::class)->lockForEdit($stale, null, ['date_time' => $this->tuesday]));

    // 1ª tentativa: só a linha (a instância achava que não ia reconciliar) → vê Atendido → desfaz.
    // 2ª: os dois locks paciente/dia, em ordem crescente, e SÓ ENTÃO a linha.
    expect($sequence)->toBe([
        ['row', null],
        ['advisory', min($origin, $destination)],
        ['advisory', max($origin, $destination)],
        ['row', null],
    ])->and($stale->situation)->toBe(ScheduleSituation::Attended);
});

it('situação omitida e instância Atendido com o banco já NÃO Atendido: os locks sobram (inofensivos), sem reinício, e a instância sai com a situação do banco', function () {
    DB::table('schedules')->where('id', $this->schedule->id)->update(['situation' => ScheduleSituation::Attended->value]);
    $stale = Schedule::query()->findOrFail($this->schedule->id); // Atendido
    DB::table('schedules')->where('id', $this->schedule->id)->update(['situation' => ScheduleSituation::Waiting->value]);

    $sequence = asseLockSequence(fn () => app(ScheduleService::class)->lockForEdit($stale, null, ['date_time' => $this->tuesday]));

    expect(array_column($sequence, 0))->toBe(['advisory', 'advisory', 'row'])
        ->and($stale->situation)->toBe(ScheduleSituation::Waiting);
});

it('situação pedida e diferente de Atendido: só a linha é travada — nenhum lock paciente/dia, nenhum reinício', function () {
    $stale = Schedule::query()->findOrFail($this->schedule->id);
    DB::table('schedules')->where('id', $this->schedule->id)->update(['situation' => ScheduleSituation::Attended->value]);

    $sequence = asseLockSequence(fn () => app(ScheduleService::class)->lockForEdit($stale, ScheduleSituation::Cancelled, ['date_time' => $this->tuesday]));

    expect($sequence)->toBe([['row', null]]);
});

it('situação pedida Atendido: os locks paciente/dia vêm antes da linha, como sempre', function () {
    $stale = Schedule::query()->findOrFail($this->schedule->id);

    $sequence = asseLockSequence(fn () => app(ScheduleService::class)->lockForEdit($stale, ScheduleSituation::Attended, ['date_time' => $this->tuesday]));

    expect(array_column($sequence, 0))->toBe(['advisory', 'advisory', 'row']);
});
