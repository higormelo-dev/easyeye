<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{ClinicResource, DoctorWorkSchedule, Entity, Schedule, ScheduleBlock, User};
use App\Models\ResourceBlock;
use App\Services\{ScheduleSeriesService, ScheduleService};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Fase 6 / S12 — série "a cada N semanas" e série ABERTA (sem total): o intervalo do tratamento se decide a cada visita
 * (injeção intravítrea "trate e estenda"), então a série nasce de um agendamento solto e cresce uma sessão por vez, com
 * a mesma regra de horário do agendamento comum.
 *
 * Relógio fixo: quinta, 01/10/2026, 07:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 07:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);

    for ($d = 1; $d <= 6; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->room      = ClinicResource::create(['entity_id' => $this->entity->id, 'name' => 'SALA DE PROCEDIMENTO', 'type' => 'room', 'active' => true]);
});

function osrAs(object $test, $entityUser = null): object
{
    $entityUser ??= $test->secretary;

    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

function osrSchedule(object $test, string $at, array $extra = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $test->entity->id, 'doctor_id' => $test->doctor->id, 'full_name' => 'PACIENTE ABERTA',
        'date_time' => Carbon::parse($at), 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ], $extra));
}

/** Série aberta: o agendamento de 05/10 vira a sessão 1 (sem total). */
function osrOpenSeries(object $test, array $extra = []): Schedule
{
    $first = osrSchedule($test, '2026-10-05 09:00', $extra);
    app(ScheduleSeriesService::class)->startOpenSeries($first);

    return $first->refresh();
}

function osrAdd(object $test, Schedule $schedule, string $at, $as = null): TestResponse
{
    return osrAs($test, $as)->postJson(route('panel.schedules.series.add-session', $schedule->id), ['date_time' => $at]);
}

describe('a cada N semanas', function () {
    it('gera as datas pelo intervalo, a 1ª é o horário escolhido', function () {
        $preview = osrAs($this)->getJson(route('panel.schedules.series.preview', [
            'doctor_id' => $this->doctor->id, 'date_time' => '2026-10-05 09:00', 'frequency' => 'every_weeks', 'interval_weeks' => 6, 'end' => 'count', 'count' => 3,
        ]))->assertOk()->json();

        expect(collect($preview['sessions'])->pluck('date_time')->all())->toBe(['2026-10-05T09:00', '2026-11-16T09:00', '2026-12-28T09:00']);
    });

    it('"até a data" respeita o intervalo e inclui o próprio dia', function () {
        $dates = app(ScheduleSeriesService::class)->generate(Carbon::parse('2026-10-05 09:00'), 'every_weeks', [], null, Carbon::parse('2026-11-16'), 6);

        expect(array_map(fn ($d) => $d->toDateString(), $dates))->toBe(['2026-10-05', '2026-11-16']);
    });

    it('intervalo de 2 a 16 semanas: fora disso (ou ausente) a prévia é recusada', function () {
        foreach ([1, 17, 0] as $weeks) {
            osrAs($this)->getJson(route('panel.schedules.series.preview', [
                'doctor_id' => $this->doctor->id, 'date_time' => '2026-10-05 09:00', 'frequency' => 'every_weeks', 'interval_weeks' => $weeks, 'end' => 'count', 'count' => 3,
            ]))->assertUnprocessable()->assertJsonValidationErrors('interval_weeks');
        }

        osrAs($this)->getJson(route('panel.schedules.series.preview', [
            'doctor_id' => $this->doctor->id, 'date_time' => '2026-10-05 09:00', 'frequency' => 'every_weeks', 'end' => 'count', 'count' => 3,
        ]))->assertUnprocessable()->assertJsonValidationErrors('interval_weeks');

        foreach ([2, 16] as $weeks) {
            osrAs($this)->getJson(route('panel.schedules.series.preview', [
                'doctor_id' => $this->doctor->id, 'date_time' => '2026-10-05 09:00', 'frequency' => 'every_weeks', 'interval_weeks' => $weeks, 'end' => 'count', 'count' => 2,
            ]))->assertOk();
        }
    });

    it('o gerador aceita os extremos de 2 e 16 semanas e recusa 1 e 17 (só a 1ª data)', function () {
        $start   = Carbon::parse('2026-10-05 09:00');
        $service = app(ScheduleSeriesService::class);

        foreach ([2, 16] as $weeks) {
            $dates = $service->generate($start, 'every_weeks', [], 3, null, $weeks);

            expect($dates)->toHaveCount(3)
                ->and($dates[1]->toDateString())->toBe($start->copy()->addWeeks($weeks)->toDateString());
        }

        foreach ([1, 17] as $weeks) {
            expect($service->generate($start, 'every_weeks', [], 3, null, $weeks))->toHaveCount(1);
        }
    });

    it('sem intervalo válido o gerador devolve só a 1ª data (nunca laço sem fim)', function () {
        $dates = app(ScheduleSeriesService::class)->generate(Carbon::parse('2026-10-05 09:00'), 'every_weeks', [], 5, null, null);

        expect($dates)->toHaveCount(1);
    });
});

describe('série aberta', function () {
    it('um agendamento solto vira a sessão 1 de uma série sem total', function () {
        $first = osrOpenSeries($this);

        expect($first->series_id)->not->toBeNull()
            ->and($first->session_number)->toBe(1)
            ->and($first->session_total)->toBeNull();
    });

    it('série que já existe não é refeita (mesma série, mesmo total)', function () {
        $first = osrOpenSeries($this);
        $id    = $first->series_id;

        app(ScheduleSeriesService::class)->startOpenSeries($first);

        expect($first->refresh()->series_id)->toBe($id);
    });

    it('acrescentar sessão: número seguinte, sem total, dados e sala herdados do agendamento', function () {
        Queue::fake();

        $first = osrOpenSeries($this, ['notes' => 'OD', 'cellphone' => '11988887777']);
        $first->resources()->sync([$this->room->id]);

        $res = osrAdd($this, $first, '2026-11-02 09:00')->assertCreated()->assertJsonPath('message', __('schedules.series_added'));

        $session = Schedule::query()->findOrFail($res->json('data.id'));

        expect($session->series_id)->toBe($first->series_id)
            ->and($session->session_number)->toBe(2)
            ->and($session->session_total)->toBeNull()
            ->and($session->date_time->format('Y-m-d H:i'))->toBe('2026-11-02 09:00')
            ->and($session->notes)->toBe('OD')
            ->and((string) $session->doctor_id)->toBe((string) $this->doctor->id)
            ->and($session->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($session->resources()->pluck('clinic_resources.id')->all())->toBe([$this->room->id]);

        osrAdd($this, $session, '2026-12-14 10:00')->assertCreated();

        expect(Schedule::query()->where('series_id', $first->series_id)->orderBy('session_number')->pluck('session_number')->all())->toBe([1, 2, 3]);

        Queue::assertPushed(NotifyScheduleChangeJob::class, 2);
    });

    it('horário que o médico já ocupa, fora do expediente ou bloqueado é recusado e nada é gravado', function () {
        $first = osrOpenSeries($this);

        osrSchedule($this, '2026-11-02 09:00', ['full_name' => 'OUTRO']);
        ScheduleBlock::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-09 08:00', 'ends_at' => '2026-11-09 12:00', 'type' => 'holiday']);

        osrAdd($this, $first, '2026-11-02 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('validation.custom.schedule.doctor_datetime_unique'));
        osrAdd($this, $first, '2026-11-09 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('actions.work_schedule_blocked'));
        osrAdd($this, $first, '2026-11-08 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('actions.work_schedule_outside', ['day' => __('actions.weekdays')[0], 'time' => '09:00']));

        expect(Schedule::query()->where('series_id', $first->series_id)->count())->toBe(1);
    });

    it('sala bloqueada na data recusa a sessão', function () {
        $first = osrOpenSeries($this);
        $first->resources()->sync([$this->room->id]);

        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => '2026-11-02 08:00', 'ends_at' => '2026-11-02 12:00', 'type' => 'other']);

        osrAdd($this, $first, '2026-11-02 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('actions.resource_blocked', ['name' => 'SALA DE PROCEDIMENTO']));
    });

    it('data repetida na série e data que já passou são recusadas', function () {
        $first = osrOpenSeries($this);

        osrAdd($this, $first, '2026-11-02 09:00')->assertCreated();
        osrAdd($this, $first, '2026-11-02 09:00')->assertUnprocessable();
        osrAdd($this, $first, '2026-09-28 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('schedules.series_add_past'));

        expect(Schedule::query()->where('series_id', $first->series_id)->count())->toBe(2);
    });

    it('série FECHADA (com total) e agendamento sem série não aceitam sessão avulsa: 404', function () {
        $closed = osrSchedule($this, '2026-10-12 09:00');
        $closed->forceFill(['series_id' => (string) Str::uuid(), 'session_number' => 1, 'session_total' => 3])->save();

        $loose = osrSchedule($this, '2026-10-19 09:00', ['full_name' => 'SOLTO']);

        osrAdd($this, $closed, '2026-11-02 09:00')->assertNotFound();
        osrAdd($this, $loose, '2026-11-02 09:00')->assertNotFound();
    });

    it('o serviço também recusa série fechada e agendamento sem série (o 404 do controlador não é a única barreira)', function () {
        $service = app(ScheduleSeriesService::class);

        $closed = osrSchedule($this, '2026-10-12 09:00');
        $closed->forceFill(['series_id' => (string) Str::uuid(), 'session_number' => 1, 'session_total' => 3])->save();

        $loose = osrSchedule($this, '2026-10-19 09:00', ['full_name' => 'SOLTO']);

        foreach ([$closed, $loose] as $anchor) {
            expect(fn () => $service->addSession($anchor, Carbon::parse('2026-11-02 09:00')))
                ->toThrow(InvalidArgumentException::class, __('schedules.series_add_closed'));
        }

        expect(Schedule::query()->where('entity_id', $this->entity->id)->count())->toBe(2);
    });

    it('encaixe: a mesma data não entra duas vezes na série (mensagem própria, não a do horário ocupado)', function () {
        $first = osrOpenSeries($this, ['is_fit_in' => true]);

        osrAdd($this, $first, '2026-11-03 09:00')->assertCreated();
        osrAdd($this, $first, '2026-11-03 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('schedules.series_duplicate_date'));

        expect(Schedule::query()->where('series_id', $first->series_id)->count())->toBe(2);
    });

    it('o detalhe do agendamento oferece "acrescentar" só na série aberta, e o rótulo da sessão não tem total', function () {
        $first = osrOpenSeries($this);

        $detail = osrAs($this)->getJson(route('panel.schedules.show', $first->id))->assertOk()->json();
        $data   = $detail['data'] ?? $detail;

        expect($data['add_session_url'])->toBe(route('panel.schedules.series.add-session', $first->id))
            ->and($data['session_number'])->toBe(1)
            ->and($data['session_total'])->toBeNull();

        $closed = osrSchedule($this, '2026-10-12 09:00', ['full_name' => 'FECHADA']);
        $closed->forceFill(['series_id' => (string) Str::uuid(), 'session_number' => 1, 'session_total' => 3])->save();

        $closedDetail = osrAs($this)->getJson(route('panel.schedules.show', $closed->id))->assertOk()->json();

        expect(($closedDetail['data'] ?? $closedDetail)['add_session_url'])->toBeNull();
    });

    it('agendamento de OUTRA clínica não existe (404) e perfil médico só acrescenta na própria agenda', function () {
        $first = osrOpenSeries($this);

        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherEu     = createEntityUser($otherEntity, User::factory()->create(), ClientRule::Secretary->value);

        osrAdd($this, $first, '2026-11-02 09:00', $otherEu)->assertNotFound();

        $colleague = createDoctorForEntity($this->entity);
        osrAdd($this, $first, '2026-11-02 09:00', $colleague->entityUser)->assertForbidden();

        osrAdd($this, $first, '2026-11-02 09:00', $this->doctor->entityUser)->assertCreated();
    });

    it('encaixe: a sessão acrescentada respeita o teto do turno (sob lock) e turno sem encaixe', function () {
        $first = osrOpenSeries($this, ['is_fit_in' => true]);

        $this->doctor->update(['fit_in_limit_morning' => 1, 'fit_in_limit_afternoon' => 0]);
        osrSchedule($this, '2026-11-02 08:30', ['full_name' => 'ENCAIXE 1', 'is_fit_in' => true]);

        osrAdd($this, $first, '2026-11-02 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', trans_choice('schedules.fit_in_limit_reached', 1, [
            'limit' => 1, 'shift' => __('schedules.shift_morning'), 'date' => '02/11/2026',
        ]));

        // Outro dia, com vaga no turno da manhã: entra como encaixe.
        $res = osrAdd($this, $first, '2026-11-03 09:00')->assertCreated();

        expect(Schedule::query()->findOrFail($res->json('data.id'))->is_fit_in)->toBeTrue()
            ->and(Schedule::query()->where('series_id', $first->series_id)->count())->toBe(2);
    });

    it('encaixe: a sessão acrescentada também respeita a escala do médico, o bloqueio dele e a sala (não só o teto do turno)', function () {
        $first = osrOpenSeries($this, ['is_fit_in' => true]);
        $first->resources()->sync([$this->room->id]);
        $this->doctor->update(['fit_in_limit_morning' => 5, 'fit_in_limit_afternoon' => 5]);

        ScheduleBlock::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-09 08:00', 'ends_at' => '2026-11-09 12:00', 'type' => 'holiday']);
        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => '2026-11-10 08:00', 'ends_at' => '2026-11-10 12:00', 'type' => 'other']);

        // Domingo: fora da escala. 09/11: médico bloqueado. 10/11: sala bloqueada. Todos cabem no teto do turno.
        osrAdd($this, $first, '2026-11-08 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('actions.work_schedule_outside', ['day' => __('actions.weekdays')[0], 'time' => '09:00']));
        osrAdd($this, $first, '2026-11-09 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('actions.work_schedule_blocked'));
        osrAdd($this, $first, '2026-11-10 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('actions.resource_blocked', ['name' => 'SALA DE PROCEDIMENTO']));

        expect(Schedule::query()->where('series_id', $first->series_id)->count())->toBe(1);

        // E um dia livre entra (o encaixe pode ocupar horário já tomado, mas não escala/bloqueio/sala).
        osrAdd($this, $first, '2026-11-11 09:00')->assertCreated();
    });

    it('sessão cancelada ou faltada libera a data: remarcar no mesmo horário não é "data repetida"', function (ScheduleSituation $freed) {
        $first   = osrOpenSeries($this);
        $session = Schedule::query()->findOrFail(osrAdd($this, $first, '2026-11-02 09:00')->assertCreated()->json('data.id'));

        $session->forceFill(['situation' => $freed->value])->saveQuietly();

        $again = osrAdd($this, $first, '2026-11-02 09:00')->assertCreated();

        expect($again->json('data.id'))->not->toBe((string) $session->id)
            ->and(Schedule::query()->where('series_id', $first->series_id)->where('date_time', '2026-11-02 09:00:00')->count())->toBe(2);
    })->with([
        'cancelada' => [ScheduleSituation::Cancelled],
        'faltou'    => [ScheduleSituation::NoShow],
    ]);

    it('sessão Atendida no mesmo horário continua sendo data repetida', function () {
        $first   = osrOpenSeries($this);
        $session = Schedule::query()->findOrFail(osrAdd($this, $first, '2026-11-02 09:00')->assertCreated()->json('data.id'));
        $session->forceFill(['situation' => ScheduleSituation::Attended->value])->saveQuietly();

        osrAdd($this, $first, '2026-11-02 09:00')->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('schedules.series_duplicate_date'));
    });

    it('acrescentar toma a cota do encaixe ANTES da trava da série (a ordem de store()), e a trava da série antes de ler a numeração', function () {
        $first = osrOpenSeries($this, ['is_fit_in' => true]);
        $this->doctor->update(['fit_in_limit_morning' => 5]);

        $at    = Carbon::parse('2026-11-02 09:00');
        $locks = [];

        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $locks[] = (int) $query->bindings[0];
            }

            if (str_contains($query->sql, 'max(') || (str_contains($query->sql, 'from "schedules"') && str_contains($query->sql, '"series_id" = ?') && str_contains($query->sql, 'for update'))) {
                $locks[] = 'series-read';
            }
        });

        DB::transaction(fn () => app(ScheduleSeriesService::class)->addSession($first->fresh(), $at));

        $quota  = array_search(ScheduleService::fitInLockKey((string) $this->doctor->id, $at), $locks, true);
        $series = array_search(ScheduleSeriesService::seriesLockKey((string) $this->entity->id, (string) $first->series_id), $locks, true);
        $read   = array_search('series-read', $locks, true);

        expect($quota)->toBeInt()->and($series)->toBeInt()->and($read)->toBeInt()
            ->and($quota)->toBeLessThan($series)
            ->and($series)->toBeLessThan($read);
    });
});
