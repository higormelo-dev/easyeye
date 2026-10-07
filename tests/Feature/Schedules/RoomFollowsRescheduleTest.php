<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{ClinicResource, Entity, ResourceBlock, ResourceWorkSchedule, Schedule, User};
use Carbon\Carbon;

/*
 * A sala/equipamento acompanha o agendamento: a remarcação individual (que
 * cria um agendamento novo) e a recorrência copiam os recursos e validam
 * escala, bloqueio e conflito da sala no novo horário — sala indisponível é
 * recusada com mensagem que diz qual sala e por quê (antes o novo agendamento
 * nascia sem a sala, em silêncio). A remarcação em lote já validava e agora
 * devolve o motivo por linha.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);
    $this->room   = ClinicResource::create(['entity_id' => $this->entity->id, 'name' => 'SALA LASER', 'type' => 'room', 'active' => true]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

    // Sala disponível seg–sex 08–18.
    for ($d = 1; $d <= 5; $d++) {
        ResourceWorkSchedule::create(['resource_id' => $this->room->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00']);
    }

    $this->monday = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
});

function rfAs(object $test): object
{
    return $test->actingAs($test->secretary->user)->withSession(panelSession($test->secretary));
}

function rfSchedule(object $test, Carbon $at, string $name = 'COM SALA', ?string $doctorId = null): Schedule
{
    $schedule = Schedule::create([
        'entity_id' => $test->entity->id, 'doctor_id' => $doctorId ?? $test->doctor->id, 'full_name' => $name,
        'date_time' => $at, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);
    $schedule->resources()->sync([$test->room->id]);

    return $schedule;
}

describe('remarcação individual', function () {
    it('o agendamento novo leva a sala', function () {
        $old = rfSchedule($this, $this->monday);

        $newId = rfAs($this)
            ->postJson(route('panel.schedules.reschedule', $old), ['date_time' => $this->monday->copy()->addDay()->format('Y-m-d H:i')])
            ->assertCreated()
            ->json('new_schedule.id');

        expect(Schedule::find($newId)->resources()->pluck('clinic_resources.id')->all())->toBe([$this->room->id])
            ->and($old->fresh()->situation)->toBe(ScheduleSituation::Cancelled);
    });

    it('sala bloqueada, fora da escala ou ocupada no novo horário: 422 dizendo qual sala e por quê, nada muda', function () {
        $old     = rfSchedule($this, $this->monday);
        $tuesday = $this->monday->copy()->addDay();
        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => $tuesday->copy()->setTime(8, 0), 'ends_at' => $tuesday->copy()->setTime(12, 0), 'type' => 'meeting']);
        rfSchedule($this, $this->monday->copy()->addDays(2)->setTime(10, 0), 'OUTRO NA SALA', createDoctorForEntity($this->entity)->id);

        $cases = [
            [$tuesday->copy()->setTime(10, 0), __('actions.resource_blocked', ['name' => 'SALA LASER'])],
            [$this->monday->copy()->addDays(5)->setTime(10, 0), __('actions.resource_unavailable', ['name' => 'SALA LASER', 'day' => __('actions.weekdays')[6], 'time' => '10:00'])],
            [$this->monday->copy()->addDays(2)->setTime(10, 0), __('actions.resource_double_booked', ['name' => 'SALA LASER'])],
        ];

        foreach ($cases as [$when, $message]) {
            rfAs($this)
                ->postJson(route('panel.schedules.reschedule', $old), ['date_time' => $when->format('Y-m-d H:i')])
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);
        }

        expect($old->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(Schedule::count())->toBe(2);
    });

    it('só trocar o médico no mesmo horário não conflita a sala com o próprio agendamento antigo', function () {
        $old   = rfSchedule($this, $this->monday);
        $other = createDoctorForEntity($this->entity);

        rfAs($this)
            ->postJson(route('panel.schedules.reschedule', $old), ['date_time' => $this->monday->format('Y-m-d H:i'), 'doctor_id' => $other->id])
            ->assertCreated();
    });
});

describe('recorrência', function () {
    it('cada ocorrência leva a sala', function () {
        rfAs($this)->postJson(route('panel.schedules.store'), [
            'doctor_id'        => $this->doctor->id,
            'full_name'        => 'RECORRENTE',
            'date_time'        => $this->monday->format('Y-m-d H:i'),
            'resource_ids'     => [$this->room->id],
            'recurrence_type'  => 'weekly',
            'recurrence_until' => $this->monday->copy()->addWeeks(2)->toDateString(),
        ])->assertCreated();

        $schedules = Schedule::query()->where('full_name', 'RECORRENTE')->get();

        expect($schedules)->toHaveCount(3)
            ->and($schedules->every(fn (Schedule $s) => $s->resources()->pluck('clinic_resources.id')->all() === [$this->room->id]))->toBeTrue();
    });

    it('sala indisponível numa ocorrência recusa o pedido inteiro, com data, sala e motivo', function () {
        $second = $this->monday->copy()->addWeek();
        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => $second->copy()->setTime(8, 0), 'ends_at' => $second->copy()->setTime(18, 0), 'type' => 'holiday']);

        rfAs($this)->postJson(route('panel.schedules.store'), [
            'doctor_id'        => $this->doctor->id,
            'full_name'        => 'RECORRENTE',
            'date_time'        => $this->monday->format('Y-m-d H:i'),
            'resource_ids'     => [$this->room->id],
            'recurrence_type'  => 'weekly',
            'recurrence_until' => $this->monday->copy()->addWeeks(2)->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonPath('message', __('schedules.recurrence_resource_unavailable', [
                'date'   => $second->format('d/m/Y H:i'),
                'reason' => __('actions.resource_blocked', ['name' => 'SALA LASER']),
            ]));

        expect(Schedule::count())->toBe(0);
    });
});

it('remarcação em lote: linha com a sala bloqueada fica de fora e o motivo volta na resposta', function () {
    $schedule = rfSchedule($this, $this->monday);
    $tuesday  = $this->monday->copy()->addDay();
    ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => $tuesday->copy()->setTime(8, 0), 'ends_at' => $tuesday->copy()->setTime(18, 0), 'type' => 'meeting']);

    $response = rfAs($this)
        ->postJson(route('panel.schedules.bulk-reschedule'), ['ids' => [$schedule->id], 'date' => $tuesday->toDateString()])
        ->assertOk()
        ->assertJsonPath('updated', 0);

    $reason = $schedule->code . ': ' . __('actions.resource_blocked', ['name' => 'SALA LASER']);
    expect($response->json('blocked.0.message'))->toBe($reason)
        ->and($response->json('message'))->toContain($reason);
});
