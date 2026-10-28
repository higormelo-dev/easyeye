<?php

declare(strict_types=1);

/**
 * Ordem dos locks do ENCAIXE marcado como Atendido — achado via revisão
 * adversarial Codex: o formulário da agenda (PUT) tomava o lock paciente/dia
 * ANTES da cota de encaixes do médico/turno (assertFitInCapacity), enquanto o
 * cadastro (POST) tomava a cota primeiro e o lock paciente/dia só depois, dentro
 * de changeSituation(). Uma criação e uma edição do mesmo paciente/dia e do
 * mesmo médico/turno, ao mesmo tempo, formavam um ciclo (cada uma segura um e
 * espera o outro): o PostgreSQL abortava uma e a ação válida virava erro.
 *
 * Ordem única agora, nos dois: cota do encaixe → lock paciente/dia → escrita da
 * linha. A ordem real, com duas conexões, está em
 * tests/Integration/FitInLockOrderTest.php.
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, DoctorWorkSchedule, Entity, Patient, Schedule, User};
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor  = createDoctorForEntity($this->entity);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true])->id]);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->monday    = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
});

/**
 * Sequência, na ordem, dos locks advisory (por chave) e da 1ª escrita na linha do agendamento.
 *
 * @return list<array{0: string, 1: int|string|null}>
 */
function filoSequence(Closure $action): array
{
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $log[] = ['advisory', $query->bindings[0] ?? null];
        } elseif (str_starts_with($sql, 'insert into "schedules"') || str_starts_with($sql, 'update "schedules"')) {
            $log[] = ['write', null];
        }
    });

    $action();

    return $log;
}

function filoFirst(array $sequence, string $kind, int|string|null $key = null): ?int
{
    foreach ($sequence as $position => [$entryKind, $entryKey]) {
        if ($entryKind === $kind && ($key === null || $entryKey === $key)) {
            return $position;
        }
    }

    return null;
}

function filoPayload(object $test, array $extra = []): array
{
    return array_merge([
        'doctor_id'  => $test->doctor->id,
        'patient_id' => $test->patient->id,
        'full_name'  => 'PACIENTE ENCAIXE',
        'date_time'  => $test->monday->format('Y-m-d H:i'),
        'is_fit_in'  => true,
        'situation'  => ScheduleSituation::Attended->value,
    ], $extra);
}

it('[GAP] CRIAR encaixe já Atendido: cota do médico/turno → lock paciente/dia → INSERT', function () {
    $quota = ScheduleService::fitInLockKey((string) $this->doctor->id, $this->monday);
    $day   = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->monday);

    $sequence = filoSequence(fn () => $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->postJson(route('panel.schedules.store'), filoPayload($this))
        ->assertCreated());

    expect(filoFirst($sequence, 'advisory', $quota))->not->toBeNull()
        ->and(filoFirst($sequence, 'advisory', $day))->not->toBeNull()
        ->and(filoFirst($sequence, 'write'))->not->toBeNull()
        ->and(filoFirst($sequence, 'advisory', $quota))->toBeLessThan(filoFirst($sequence, 'advisory', $day))
        ->and(filoFirst($sequence, 'advisory', $day))->toBeLessThan(filoFirst($sequence, 'write'));
});

it('[GAP] EDITAR encaixe pra Atendido: a MESMA ordem — cota do médico/turno → lock paciente/dia → UPDATE', function () {
    $schedule = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
        'full_name' => 'PACIENTE ENCAIXE', 'date_time' => $this->monday, 'situation' => ScheduleSituation::Waiting->value,
        'is_fit_in' => true, 'active' => true,
    ]);

    $quota = ScheduleService::fitInLockKey((string) $this->doctor->id, $this->monday);
    $day   = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->monday);

    $sequence = filoSequence(fn () => $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->putJson(route('panel.schedules.update', $schedule), filoPayload($this, ['notes' => 'chegou']))
        ->assertOk());

    expect(filoFirst($sequence, 'advisory', $quota))->not->toBeNull()
        ->and(filoFirst($sequence, 'advisory', $day))->not->toBeNull()
        ->and(filoFirst($sequence, 'write'))->not->toBeNull()
        ->and(filoFirst($sequence, 'advisory', $quota))->toBeLessThan(filoFirst($sequence, 'advisory', $day))
        ->and(filoFirst($sequence, 'advisory', $day))->toBeLessThan(filoFirst($sequence, 'write'))
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
});

it('encaixe SEM pedir Atendido não toma o lock paciente/dia (só a cota)', function () {
    $day = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->monday);

    $sequence = filoSequence(fn () => $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->postJson(route('panel.schedules.store'), filoPayload($this, ['situation' => ScheduleSituation::Scheduled->value]))
        ->assertCreated());

    expect(filoFirst($sequence, 'advisory', $day))->toBeNull()
        ->and(filoFirst($sequence, 'advisory', ScheduleService::fitInLockKey((string) $this->doctor->id, $this->monday)))->not->toBeNull();
});
