<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{ClinicResource, Entity, ResourceBlock, ResourceWorkSchedule, Schedule, User};
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Escala e bloqueios de sala/equipamento: as rotas existiam e a Agenda já
 * validava (ScheduleService::isResourceWithinSchedule/isResourceBlocked), mas
 * não havia tela. Agora: página "Escala e bloqueios" (ação da linha em
 * Configurações › Recursos), com o mesmo acesso das rotas
 * (entity.role:admin,doctor,secretary) e recurso sempre da clínica da sessão.
 */
beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->resource = ClinicResource::create(['entity_id' => $this->entity->id, 'name' => 'SALA DE EXAMES 1', 'type' => 'room', 'active' => true]);

    $this->admin     = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);
    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->financial = createEntityUser($this->entity, User::factory()->create(), ClientRule::Financial->value);

    $this->other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherResource = ClinicResource::create(['entity_id' => $this->other->id, 'name' => 'SALA ALHEIA', 'type' => 'room', 'active' => true]);
});

function f0ResourceAs(object $test, $entityUser): object
{
    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

/** Escala de segunda a sexta, 08–12 e 13–18 (o resto do payload desligado). */
function f0WeekdaysPayload(array $override = []): array
{
    $days = [];

    for ($d = 0; $d <= 6; $d++) {
        $days[] = [
            'day'    => $d,
            'active' => $d >= 1 && $d <= 5,
            'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00'], ['starts_at' => '13:00', 'ends_at' => '18:00']],
        ];
    }

    foreach ($override as $day => $data) {
        $days[$day] = array_merge($days[$day], $data);
    }

    return ['days' => $days];
}

describe('tela', function () {
    it('admin e secretária abrem a página com escala, bloqueios e tipos traduzidos', function () {
        ResourceWorkSchedule::create(['resource_id' => $this->resource->id, 'day_of_week' => 2, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00']);
        ResourceBlock::create(['resource_id' => $this->resource->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'type' => 'meeting', 'reason' => 'Troca do ar']);

        foreach ([$this->admin, $this->secretary] as $eu) {
            f0ResourceAs($this, $eu)
                ->get(route('panel.resources.work-schedule.index', $this->resource->id))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Panel/Settings/Resources/WorkSchedule')
                    ->where('resource.name', 'SALA DE EXAMES 1')
                    ->where('resource.type_label', __('resource_schedule.resource_types.room'))
                    ->where('days.2.active', true)
                    ->where('days.2.ranges', [['starts_at' => '09:00', 'ends_at' => '11:00']])
                    ->where('days.0.active', false)
                    ->where('blocks.0.type_label', __('resource_schedule.block_types.meeting'))
                    ->where('blocks.0.reason', 'Troca do ar')
                    ->where('blockTypes', fn ($types) => collect($types)->pluck('value')->all() === ['absence', 'holiday', 'meeting', 'other'])
                    ->where('urls.sync', route('panel.resources.work-schedule.sync', $this->resource->id))
                    ->where('t.title', __('resource_schedule.title')));
        }
    });

    it('perfil fora das rotas (financeiro) recebe 403; recurso de outra clínica é 404', function () {
        f0ResourceAs($this, $this->financial)->get(route('panel.resources.work-schedule.index', $this->resource->id))->assertForbidden();
        f0ResourceAs($this, $this->admin)->get(route('panel.resources.work-schedule.index', $this->otherResource->id))->assertNotFound();
    });

    it('Configurações › Recursos oferece a ação "Escala e bloqueios" por linha', function () {
        f0ResourceAs($this, $this->admin)
            ->get(route('panel.setting.resources.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rowLinks.0.key', 'work_schedule')
                ->where('rowLinks.0.label', __('resource_schedule.action'))
                ->where('rowLinks.0.url', route('panel.resources.work-schedule.index', ['__ID__'])));
    });
});

describe('escala (sync)', function () {
    it('grava a grade semanal', function () {
        f0ResourceAs($this, $this->admin)
            ->putJson(route('panel.resources.work-schedule.sync', $this->resource->id), f0WeekdaysPayload())
            ->assertOk();

        expect(ResourceWorkSchedule::where('resource_id', $this->resource->id)->count())->toBe(10);
    });

    it('fim antes do início, início repetido e sobreposição viram 422 legível (nada muda; antes: descarte silencioso / 500)', function () {
        ResourceWorkSchedule::create(['resource_id' => $this->resource->id, 'day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '08:00:00']);

        $cases = [
            [1 => ['ranges' => [['starts_at' => '12:00', 'ends_at' => '08:00']]]],
            [1 => ['ranges' => [['starts_at' => '08:00', 'ends_at' => '10:00'], ['starts_at' => '08:00', 'ends_at' => '12:00']]]],
            [1 => ['ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00'], ['starts_at' => '11:00', 'ends_at' => '14:00']]]],
        ];

        foreach ($cases as $override) {
            f0ResourceAs($this, $this->admin)
                ->putJson(route('panel.resources.work-schedule.sync', $this->resource->id), f0WeekdaysPayload($override))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['days']);
        }

        expect(ResourceWorkSchedule::where('resource_id', $this->resource->id)->pluck('starts_at')->all())->toBe(['07:00:00']);
    });

    it('horário em formato inválido é 422; recurso de outra clínica é 404', function () {
        f0ResourceAs($this, $this->admin)
            ->putJson(route('panel.resources.work-schedule.sync', $this->resource->id), f0WeekdaysPayload([1 => ['ranges' => [['starts_at' => '8h', 'ends_at' => '12:00']]]]))
            ->assertUnprocessable();

        f0ResourceAs($this, $this->admin)
            ->putJson(route('panel.resources.work-schedule.sync', $this->otherResource->id), f0WeekdaysPayload())
            ->assertNotFound();
    });
});

describe('bloqueios', function () {
    it('cria e remove; período invertido e tipo inválido são 422', function () {
        $payload = ['type' => 'meeting', 'starts_at' => now()->addDay()->format('Y-m-d H:i'), 'ends_at' => now()->addDays(2)->format('Y-m-d H:i'), 'reason' => 'Manutenção'];

        $created = f0ResourceAs($this, $this->secretary)
            ->postJson(route('panel.resources.blocks.store', $this->resource->id), $payload)
            ->assertCreated()
            ->assertJsonPath('data.type_label', __('resource_schedule.block_types.meeting'))
            ->json('data');

        f0ResourceAs($this, $this->secretary)
            ->postJson(route('panel.resources.blocks.store', $this->resource->id), ['ends_at' => $payload['starts_at'], 'starts_at' => $payload['ends_at']] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
        f0ResourceAs($this, $this->secretary)
            ->postJson(route('panel.resources.blocks.store', $this->resource->id), ['type' => 'party'] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);

        f0ResourceAs($this, $this->secretary)
            ->deleteJson(route('panel.resources.blocks.destroy', [$this->resource->id, $created['id']]))
            ->assertOk();

        expect(ResourceBlock::count())->toBe(0);
    });

    it('não cria nem remove bloqueio de recurso de outra clínica', function () {
        $foreign = ResourceBlock::create(['resource_id' => $this->otherResource->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'type' => 'other']);

        f0ResourceAs($this, $this->admin)
            ->postJson(route('panel.resources.blocks.store', $this->otherResource->id), ['type' => 'other', 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString()])
            ->assertNotFound();
        f0ResourceAs($this, $this->admin)
            ->deleteJson(route('panel.resources.blocks.destroy', [$this->resource->id, $foreign->id]))
            ->assertNotFound();

        expect(ResourceBlock::find($foreign->id))->not->toBeNull();
    });
});

describe('Agenda respeita escala e bloqueio da sala (regressão)', function () {
    beforeEach(function () {
        $this->doctor = createDoctorForEntity($this->entity);
        // Próxima segunda-feira às 10h (dentro da escala do recurso abaixo).
        $this->monday = Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0);

        f0ResourceAs($this, $this->admin)
            ->putJson(route('panel.resources.work-schedule.sync', $this->resource->id), f0WeekdaysPayload())
            ->assertOk();
    });

    it('agendar fora da escala da sala ou com a sala bloqueada é recusado; dentro, passa', function () {
        $book = fn (Carbon $at, string $name) => f0ResourceAs($this, $this->secretary)->postJson(route('panel.schedules.store'), [
            'doctor_id'    => $this->doctor->id,
            'full_name'    => $name,
            'date_time'    => $at->format('Y-m-d H:i'),
            'resource_ids' => [$this->resource->id],
        ]);

        $book($this->monday->copy()->setTime(12, 30), 'ALMOCO')->assertUnprocessable()->assertJsonValidationErrors(['date_time']);
        $book($this->monday->copy()->next(Carbon::SUNDAY)->setTime(10, 0), 'DOMINGO')->assertUnprocessable()->assertJsonValidationErrors(['date_time']);

        ResourceBlock::create(['resource_id' => $this->resource->id, 'starts_at' => $this->monday->copy()->setTime(9, 0), 'ends_at' => $this->monday->copy()->setTime(11, 0), 'type' => 'meeting']);
        $book($this->monday, 'BLOQUEADO')->assertUnprocessable()->assertJsonValidationErrors(['date_time']);

        $book($this->monday->copy()->setTime(14, 0), 'OK')->assertCreated();
    });

    it('remarcação em lote não move agendamento para horário em que a sala está bloqueada', function () {
        $schedule = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'full_name' => 'LOTE SALA',
            'date_time' => $this->monday, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        $schedule->resources()->sync([$this->resource->id]);

        $tuesday = $this->monday->copy()->addDay();
        ResourceBlock::create(['resource_id' => $this->resource->id, 'starts_at' => $tuesday->copy()->setTime(8, 0), 'ends_at' => $tuesday->copy()->setTime(18, 0), 'type' => 'meeting']);

        f0ResourceAs($this, $this->secretary)
            ->postJson(route('panel.schedules.bulk-reschedule'), ['ids' => [$schedule->id], 'date' => $tuesday->toDateString()])
            ->assertOk()
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('skipped', 1);

        expect($schedule->fresh()->date_time->toDateTimeString())->toBe($this->monday->toDateTimeString());
    });
});
