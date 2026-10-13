<?php

declare(strict_types=1);

/**
 * Locks paciente/dia de um agendamento Atendido EDITADO pra outro dia ou outro
 * paciente — achado via revisão adversarial Codex: o PUT só travava o
 * paciente/dia do DESTINO. A reconciliação das capturas trava todos os
 * agendamentos do paciente naquele dia (pela identidade já comitada); duas
 * edições em sentidos opostos (X→Y e Y→X), cada uma segurando a linha do PRÓPRIO
 * agendamento, esperavam a do outro: deadlock. Agora origem E destino são
 * travados, em ordem determinística (pela chave), antes de qualquer linha, e a
 * identidade é relida sob a trava (reinicia se mudou). A corrida real, com duas
 * conexões, está em tests/Integration/AttendedOppositeMovesTest.php.
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, DoctorWorkSchedule, Entity, Patient, Schedule, User};
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $covenant       = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $covenant->id]);
    $this->other    = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $covenant->id]);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->monday   = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
    $this->tuesday  = $this->monday->copy()->addDay();
    $this->thursday = $this->monday->copy()->addDays(3);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

    $this->schedule = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
        'full_name' => 'PACIENTE TROCA', 'date_time' => $this->monday,
        'situation' => ScheduleSituation::Attended->value, 'active' => true,
    ]);
});

function amloKey(object $test, object $patient, Carbon $day): int
{
    return Schedule::patientDayLockKey((string) $test->entity->id, (string) $patient->id, $day);
}

/**
 * Sequência dos locks advisory (por chave), das travas de linha e do 1º UPDATE do agendamento.
 *
 * @return list<array{0: string, 1: int|string|null}>
 */
function amloSequence(Closure $action): array
{
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $log[] = ['advisory', $query->bindings[0] ?? null];
        } elseif (str_contains($sql, 'for update') && str_contains($sql, 'from "schedules"')) {
            $log[] = ['row', null];
        } elseif (str_starts_with($sql, 'update "schedules"')) {
            $log[] = ['update', null];
        }
    });

    $action();

    return $log;
}

function amloPut(object $test, array $overrides = []): TestResponse
{
    return $test->actingAs($test->secretary->user)->withSession(panelSession($test->secretary))
        ->putJson(route('panel.schedules.update', $test->schedule), array_merge([
            'doctor_id'  => $test->doctor->id,
            'patient_id' => $test->patient->id,
            'full_name'  => 'PACIENTE TROCA',
            'date_time'  => $test->monday->format('Y-m-d H:i'),
            'situation'  => ScheduleSituation::Attended->value,
        ], $overrides));
}

/** @return list<int> chaves advisory ANTES do 1º UPDATE do agendamento, na ordem em que foram pedidas */
function amloAdvisoryBeforeUpdate(array $sequence): array
{
    $keys = [];

    foreach ($sequence as [$kind, $key]) {
        if ($kind === 'update') {
            break;
        }

        if ($kind === 'advisory') {
            $keys[] = $key;
        }
    }

    return $keys;
}

it('[GAP] PUT que muda o DIA de um Atendido trava origem E destino, em ordem crescente de chave (nos dois sentidos), antes de qualquer linha e do UPDATE', function (string $from, string $to) {
    // Nos dois sentidos: qualquer que seja a ordem das chaves, um dos dois sentidos é "decrescente" sem a ordenação.
    $this->schedule->update(['date_time' => $this->{$from}]);

    $origin      = amloKey($this, $this->patient, $this->{$from});
    $destination = amloKey($this, $this->patient, $this->{$to});

    $sequence = amloSequence(fn () => amloPut($this, [
        'date_time' => $this->{$to}->format('Y-m-d H:i'),
    ])->assertOk());

    $firstRow = array_search(['row', null], $sequence, true);

    // Os dois locks, na ordem crescente, e só depois a primeira trava de linha.
    expect(array_slice(amloAdvisoryBeforeUpdate($sequence), 0, 2))->toBe([min($origin, $destination), max($origin, $destination)])
        ->and($firstRow)->toBeInt()
        ->and(array_search(['advisory', max($origin, $destination)], $sequence, true))->toBeLessThan($firstRow)
        ->and($this->schedule->fresh()->date_time->toDateString())->toBe($this->{$to}->toDateString());
})->with([
    'segunda → terça' => ['monday', 'tuesday'],
    'terça → segunda' => ['tuesday', 'monday'],
]);

it('[GAP] PUT que troca o PACIENTE de um Atendido também trava a origem e o destino', function () {
    $origin      = amloKey($this, $this->patient, $this->monday);
    $destination = amloKey($this, $this->other, $this->monday);

    $sequence = amloSequence(fn () => amloPut($this, ['patient_id' => $this->other->id])->assertOk());

    expect(array_slice(amloAdvisoryBeforeUpdate($sequence), 0, 2))->toBe([min($origin, $destination), max($origin, $destination)]);
});

it('PUT que NÃO muda paciente nem dia (anotação, horário no mesmo dia) toma um lock paciente/dia só — origem e destino são o mesmo', function () {
    $key = amloKey($this, $this->patient, $this->monday);

    $sequence = amloSequence(fn () => amloPut($this, ['notes' => 'só anotação', 'date_time' => $this->monday->copy()->setTime(9, 30)->format('Y-m-d H:i')])->assertOk());

    // Só o lock desta identidade antes do UPDATE (as reconciliações depois reaproveitam o mesmo).
    expect(array_unique(amloAdvisoryBeforeUpdate($sequence)))->toBe([$key]);
});

it('PUT que tira o agendamento de Atendido não toma lock paciente/dia nenhum (nada a reconciliar)', function () {
    $sequence = amloSequence(fn () => amloPut($this, [
        'date_time' => $this->tuesday->format('Y-m-d H:i'), 'situation' => ScheduleSituation::Waiting->value,
    ])->assertOk());

    expect(amloAdvisoryBeforeUpdate($sequence))->toBe([]);
});

it('[GAP] identidade de ORIGEM mudou por outra sessão depois de carregada: relê sob a trava e reinicia com a origem real (quinta), sem segurar linha enquanto espera o lock novo', function () {
    $stale = Schedule::query()->findOrFail($this->schedule->id); // carregada na segunda

    // Outra sessão remarca pra quinta e comita.
    DB::table('schedules')->where('id', $this->schedule->id)->update(['date_time' => $this->thursday]);

    $monday   = amloKey($this, $this->patient, $this->monday);
    $thursday = amloKey($this, $this->patient, $this->thursday);
    $tuesday  = amloKey($this, $this->patient, $this->tuesday);

    $sequence = amloSequence(fn () => app(ScheduleService::class)->lockForEdit($stale, ScheduleSituation::Attended, ['date_time' => $this->tuesday]));

    $rows = array_keys(array_filter($sequence, fn (array $e) => $e[0] === 'row'));

    // 1ª tentativa: {segunda, terça} → linha (vê a quinta). 2ª: {quinta, terça} → linha.
    expect($rows)->toHaveCount(2)
        ->and(array_slice(array_column(array_slice($sequence, 0, $rows[0]), 1), 0))->toBe([min($monday, $tuesday), max($monday, $tuesday)])
        ->and(array_column(array_slice($sequence, $rows[0] + 1, $rows[1] - $rows[0] - 1), 1))->toBe([min($thursday, $tuesday), max($thursday, $tuesday)])
        // A instância sai com a origem de verdade.
        ->and($stale->date_time->toDateString())->toBe($this->thursday->toDateString());
});

it('lockForEdit de quem não fica Atendido trava só a linha — nenhum lock paciente/dia', function () {
    $stale = Schedule::query()->findOrFail($this->schedule->id);

    $sequence = amloSequence(fn () => app(ScheduleService::class)->lockForEdit($stale, ScheduleSituation::Waiting, ['date_time' => $this->tuesday]));

    expect($sequence)->toBe([['row', null]]);
});
