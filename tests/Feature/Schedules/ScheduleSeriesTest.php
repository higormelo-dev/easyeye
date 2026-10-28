<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{ClinicResource, DoctorWorkSchedule, Entity, ResourceBlock, Schedule, ScheduleBlock, ScheduleSituationLog, User};
use App\Services\ScheduleSeriesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Fase 1 / A7 — série de sessões (injeção intravítrea, campimetria seriada,
 * ortóptica): "Repetir" com semanal, mensal ou dias da semana; N sessões ou
 * até a data (inclusive); prévia com os conflitos de cada data; gravação tudo
 * ou nada; selo "3/10"; cancelar as futuras sem tocar nas passadas/atendidas.
 *
 * Relógio fixo: quinta, 01/10/2026, 07:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 07:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);

    // Seg–sáb 08–12, intervalo 15.
    for ($d = 1; $d <= 6; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->room      = ClinicResource::create(['entity_id' => $this->entity->id, 'name' => 'CAMPÍMETRO', 'type' => 'equipment', 'active' => true]);
});

function sssAs(object $test, $entityUser = null): object
{
    $entityUser ??= $test->secretary;

    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

function sssPreview(object $test, array $params): array
{
    return sssAs($test)->getJson(route('panel.schedules.series.preview', array_merge([
        'doctor_id' => $test->doctor->id,
        'date_time' => '2026-10-05 09:00',
    ], $params)))->assertOk()->json();
}

function sssStore(object $test, array $seriesDates, array $extra = []): TestResponse
{
    return sssAs($test)->postJson(route('panel.schedules.store'), array_merge([
        'doctor_id'    => $test->doctor->id,
        'full_name'    => 'PACIENTE SÉRIE',
        'date_time'    => '2026-10-05 09:00',
        'series_dates' => $seriesDates,
    ], $extra));
}

function sssBook(object $test, string $at, array $extra = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $test->entity->id, 'doctor_id' => $test->doctor->id, 'full_name' => 'OCUPA',
        'date_time' => Carbon::parse($at), 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ], $extra));
}

function sssSeries(object $test): array
{
    sssStore($test, ['2026-10-07 09:00', '2026-10-12 09:00', '2026-10-14 09:00', '2026-10-19 09:00'])->assertCreated();

    return Schedule::query()->where('full_name', 'PACIENTE SÉRIE')->orderBy('date_time')->get()->all();
}

describe('datas da série', function () {
    it('dias da semana (seg e qua) com N sessões, a 1ª é o horário escolhido', function () {
        $preview = sssPreview($this, ['frequency' => 'weekdays', 'weekdays' => [1, 3], 'end' => 'count', 'count' => 5]);

        expect(collect($preview['sessions'])->pluck('date_time')->all())->toBe([
            '2026-10-05T09:00', '2026-10-07T09:00', '2026-10-12T09:00', '2026-10-14T09:00', '2026-10-19T09:00',
        ])->and(collect($preview['sessions'])->pluck('number')->all())->toBe([1, 2, 3, 4, 5]);
    });

    it('"até a data" inclui o próprio dia; semanal e mensal (31 vira o fim do mês)', function () {
        $weekly = sssPreview($this, ['frequency' => 'weekly', 'end' => 'until', 'until' => '2026-10-26']);
        expect(collect($weekly['sessions'])->pluck('date_time')->last())->toBe('2026-10-26T09:00')
            ->and($weekly['sessions'])->toHaveCount(4);

        $dates = app(ScheduleSeriesService::class)->generate(Carbon::parse('2026-10-31 09:00'), 'monthly', [], 3);
        expect(array_map(fn ($d) => $d->toDateString(), $dates))->toBe(['2026-10-31', '2026-11-30', '2026-12-31']);
    });

    it('teto de 60 sessões: a prévia corta e avisa; o servidor recusa mais de 60', function () {
        $preview = sssPreview($this, ['frequency' => 'weekdays', 'weekdays' => [1, 2, 3, 4, 5, 6], 'end' => 'until', 'until' => '2027-12-31']);

        expect($preview['sessions'])->toHaveCount(ScheduleSeriesService::MAX_SESSIONS)
            ->and($preview['truncated'])->toBeTrue()
            ->and($preview['max'])->toBe(60);

        sssAs($this)->getJson(route('panel.schedules.series.preview', [
            'doctor_id' => $this->doctor->id, 'date_time' => '2026-10-05 09:00', 'frequency' => 'weekly', 'end' => 'count', 'count' => 61,
        ]))->assertUnprocessable()->assertJsonValidationErrors('count');

        $tooMany = collect(range(1, 60))->map(fn ($i) => Carbon::parse('2026-10-05 09:00')->addWeeks($i)->format('Y-m-d H:i'))->all();
        sssStore($this, $tooMany)->assertUnprocessable()->assertJsonValidationErrors('series_dates');
        expect(Schedule::count())->toBe(0);
    });

    it('prévia mostra o conflito de cada data (médico ocupado, bloqueio, sala, fora do expediente)', function () {
        sssBook($this, '2026-10-12 09:00');
        ScheduleBlock::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-19 08:00', 'ends_at' => '2026-10-19 12:00', 'type' => 'holiday']);
        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => '2026-10-26 08:00', 'ends_at' => '2026-10-26 12:00', 'type' => 'other']);

        $preview = sssPreview($this, ['frequency' => 'weekly', 'end' => 'count', 'count' => 5, 'resource_ids' => [$this->room->id]]);
        $errors  = collect($preview['sessions'])->pluck('errors', 'date_time');

        expect($errors['2026-10-05T09:00'])->toBe([])
            ->and($errors['2026-10-12T09:00'][0])->toBe(__('validation.custom.schedule.doctor_datetime_unique'))
            ->and($errors['2026-10-19T09:00'][0])->toBe(__('actions.work_schedule_blocked'))
            ->and($errors['2026-10-26T09:00'][0])->toBe(__('actions.resource_blocked', ['name' => 'CAMPÍMETRO']))
            ->and($errors['2026-11-02T09:00'])->toBe([]);

        // Domingo fora do expediente.
        $sunday = sssPreview($this, ['date_time' => '2026-10-10 09:00', 'frequency' => 'weekdays', 'weekdays' => [0, 6], 'end' => 'count', 'count' => 2]);
        expect($sunday['sessions'][1]['errors'][0])->toBe(__('actions.work_schedule_outside', ['day' => __('actions.weekdays')[0], 'time' => '09:00']));
    });

    it('perfil médico só faz prévia da própria agenda', function () {
        $colleague = createDoctorForEntity($this->entity);

        sssAs($this, $this->doctor->entityUser)->getJson(route('panel.schedules.series.preview', [
            'doctor_id' => $colleague->id, 'date_time' => '2026-10-05 09:00', 'frequency' => 'weekly', 'end' => 'count', 'count' => 2,
        ]))->assertUnprocessable()->assertJsonPath('errors.doctor_id.0', __('schedules.doctor_only_own'));
    });
});

describe('gravação tudo ou nada', function () {
    it('grava todas as sessões com série, número/total, sala e os dados do 1º agendamento', function () {
        Queue::fake();

        sssStore($this, ['2026-10-07 09:00', '2026-10-12 09:00'], ['resource_ids' => [$this->room->id], 'notes' => 'OD', 'is_fit_in' => false])
            ->assertCreated()
            ->assertJsonPath('message', __('schedules.series_created', ['count' => 3]));

        $sessions = Schedule::query()->orderBy('date_time')->get();

        expect($sessions)->toHaveCount(3)
            ->and($sessions->pluck('series_id')->unique())->toHaveCount(1)
            ->and($sessions->first()->series_id)->not->toBeNull()
            ->and($sessions->pluck('session_number')->all())->toBe([1, 2, 3])
            ->and($sessions->pluck('session_total')->unique()->all())->toBe([3])
            ->and($sessions->pluck('notes')->unique()->all())->toBe(['OD'])
            ->and($sessions->every(fn (Schedule $s) => $s->resources()->pluck('clinic_resources.id')->all() === [$this->room->id]))->toBeTrue()
            ->and($sessions->every(fn (Schedule $s) => $s->situation === ScheduleSituation::Scheduled))->toBeTrue();

        // Aviso de criação: um só (da 1ª sessão), como na recorrência de sempre.
        Queue::assertPushed(NotifyScheduleChangeJob::class, 1);
    });

    it('uma data com conflito recusa a série inteira, dizendo qual data e por quê', function () {
        sssBook($this, '2026-10-12 09:00');

        sssStore($this, ['2026-10-07 09:00', '2026-10-12 09:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', [
                'date'   => '12/10/2026 09:00',
                'reason' => __('validation.custom.schedule.doctor_datetime_unique'),
            ]));

        // Bloqueio do médico e sala bloqueada (não há índice no banco que
        // barre): só a validação de cada data impede gravar.
        ScheduleBlock::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-19 08:00', 'ends_at' => '2026-10-19 12:00', 'type' => 'holiday']);
        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => '2026-10-26 08:00', 'ends_at' => '2026-10-26 12:00', 'type' => 'other']);

        sssStore($this, ['2026-10-07 09:00', '2026-10-19 09:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', ['date' => '19/10/2026 09:00', 'reason' => __('actions.work_schedule_blocked')]));

        sssStore($this, ['2026-10-07 09:00', '2026-10-26 09:00'], ['resource_ids' => [$this->room->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', ['date' => '26/10/2026 09:00', 'reason' => __('actions.resource_blocked', ['name' => 'CAMPÍMETRO'])]));

        expect(Schedule::where('full_name', 'PACIENTE SÉRIE')->count())->toBe(0);
    });

    it('conflito que aparece só na gravação (corrida) desfaz tudo', function () {
        // A validação passa; um "outro usuário" grava 12/10 09:00 no meio da transação.
        Schedule::creating(function (Schedule $s) {
            if ($s->full_name === 'PACIENTE SÉRIE' && $s->date_time->format('Y-m-d H:i') === '2026-10-12 09:00') {
                Schedule::withoutEvents(fn () => Schedule::query()->insert([
                    'id'        => (string) Str::uuid(), 'entity_id' => $s->entity_id, 'doctor_id' => $s->doctor_id,
                    'code'      => 'SDL-8888888888', 'full_name' => 'CONCORRENTE', 'date_time' => '2026-10-12 09:00:00',
                    'situation' => ScheduleSituation::Scheduled->value, 'active' => true, 'cellphone_whatsapp' => false,
                    'is_fit_in' => false, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        });

        sssStore($this, ['2026-10-07 09:00', '2026-10-12 09:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', [
                'date'   => '12/10/2026 09:00',
                'reason' => __('validation.custom.schedule.doctor_datetime_unique'),
            ]));

        expect(Schedule::where('full_name', 'PACIENTE SÉRIE')->count())->toBe(0);
    });

    it('série de encaixes respeita o teto do turno em cada data', function () {
        $this->doctor->update(['fit_in_limit_morning' => 1]);
        sssBook($this, '2026-10-12 10:00', ['is_fit_in' => true]);

        sssStore($this, ['2026-10-07 09:00', '2026-10-12 09:00'], ['is_fit_in' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', [
                'date'   => '12/10/2026 09:00',
                'reason' => trans_choice('schedules.fit_in_limit_reached', 1, ['limit' => 1, 'shift' => __('schedules.shift_morning'), 'date' => '12/10/2026']),
            ]));

        sssStore($this, ['2026-10-07 09:00'], ['is_fit_in' => true])->assertCreated();
        expect(Schedule::where('full_name', 'PACIENTE SÉRIE')->pluck('is_fit_in')->unique()->all())->toBe([true]);
    });

    it('data repetida ou anterior à 1ª sessão é recusada; série não existe na edição', function () {
        sssStore($this, ['2026-10-07 09:00', '2026-10-07 09:00'])->assertUnprocessable()->assertJsonValidationErrors('series_dates.0');
        sssStore($this, ['2026-10-02 09:00'])->assertUnprocessable()->assertJsonValidationErrors('series_dates.0');

        $existing = sssBook($this, '2026-10-05 10:00');
        sssAs($this)->putJson(route('panel.schedules.update', $existing), [
            'doctor_id' => $this->doctor->id, 'full_name' => 'OCUPA', 'date_time' => '2026-10-05 10:00', 'series_dates' => ['2026-10-12 10:00'],
        ])->assertUnprocessable()->assertJsonValidationErrors('series_dates');

        expect(Schedule::count())->toBe(1);
    });

    it('recorrência antiga (semanal até a data) vira série numerada', function () {
        sssAs($this)->postJson(route('panel.schedules.store'), [
            'doctor_id'       => $this->doctor->id, 'full_name' => 'RECORRENTE', 'date_time' => '2026-10-05 09:00',
            'recurrence_type' => 'weekly', 'recurrence_until' => '2026-10-19',
        ])->assertCreated();

        $sessions = Schedule::query()->where('full_name', 'RECORRENTE')->orderBy('date_time')->get();

        expect($sessions->pluck('session_number')->all())->toBe([1, 2, 3])
            ->and($sessions->pluck('session_total')->unique()->all())->toBe([3])
            ->and($sessions->pluck('series_id')->unique())->toHaveCount(1)
            ->and($sessions->first()->series_id)->toBe($sessions->first()->recurrence_group_id);
    });
});

describe('ações na série', function () {
    it('cancelar as futuras: só as que ainda não aconteceram, com o motivo; passadas, atendidas e em andamento ficam', function () {
        Queue::fake();
        [$s1, $s2, $s3, $s4, $s5] = sssSeries($this);

        $this->travelTo(Carbon::parse('2026-10-12 08:30:00'));
        $s1->update(['situation' => ScheduleSituation::Attended->value]);   // passada, atendida
        $s2->update(['situation' => ScheduleSituation::Scheduled->value]);  // passada, ficou agendada (não marcada)
        $s3->update(['situation' => ScheduleSituation::Waiting->value, 'arrived_at' => now()]); // hoje 09:00, já chegou
        $s4->update(['situation' => ScheduleSituation::Confirmed->value]);  // futura confirmada

        sssAs($this)->postJson(route('panel.schedules.series.cancel-future', $s1), ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        sssAs($this)->postJson(route('panel.schedules.series.cancel-future', $s1), ['reason' => 'Tratamento encerrado'])
            ->assertOk()
            ->assertJsonPath('cancelled', 2)
            ->assertJsonPath('message', trans_choice('schedules.series_cancelled', 2, ['count' => 2]));

        expect($s1->fresh()->situation)->toBe(ScheduleSituation::Attended)
            ->and($s2->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($s3->fresh()->situation)->toBe(ScheduleSituation::Waiting)
            ->and($s4->fresh()->situation)->toBe(ScheduleSituation::Cancelled)
            ->and($s5->fresh()->situation)->toBe(ScheduleSituation::Cancelled)
            ->and($s5->fresh()->cancellation_reason)->toBe('Tratamento encerrado')
            ->and(ScheduleSituationLog::where('schedule_id', $s5->id)->latest('created_at')->first()->notes)->toBe('Tratamento encerrado');

        Queue::assertPushed(NotifyScheduleChangeJob::class, fn ($job) => $job->event === 'cancelled' && in_array($job->scheduleId, [$s4->id, $s5->id], true));
    });

    it('remarcar uma sessão mantém o número na série; o detalhe lista a série inteira', function () {
        [$s1, $s2] = sssSeries($this);

        $newId = sssAs($this)->postJson(route('panel.schedules.reschedule', $s2), ['date_time' => '2026-10-08 10:00'])
            ->assertCreated()
            ->json('new_schedule.id');

        $new = Schedule::find($newId);
        expect($new->series_id)->toBe($s1->series_id)
            ->and([$new->session_number, $new->session_total])->toBe([2, 5]);

        $series = sssAs($this)->getJson(route('panel.schedules.show', $new))->assertOk()->json('data');

        expect($series['session_number'])->toBe(2)
            ->and($series['series'])->toHaveCount(6) // 5 + a remarcada (cancelada)
            ->and(collect($series['series'])->firstWhere('is_current', true)['id'])->toBe($newId)
            ->and($series['series_cancellable'])->toBe(5) // todas ainda por vir (a remarcada já está cancelada)
            ->and($series['cancel_series_url'])->toBe(route('panel.schedules.series.cancel-future', $newId));
    });

    it('linha da agenda traz "n/N"; série de outra clínica não é alcançável', function () {
        [$s1] = sssSeries($this);

        sssAs($this)->get(route('panel.schedules.index', ['date' => '2026-10-05']))
            ->assertInertia(fn ($page) => $page->where('scheduleItems.0.session_number', 1)->where('scheduleItems.0.session_total', 5));

        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherEu = createEntityUser($other, User::factory()->create(), ClientRule::Admin->value);

        sssAs($this, $otherEu)->postJson(route('panel.schedules.series.cancel-future', $s1->id), ['reason' => 'x'])->assertNotFound();
        expect(Schedule::where('series_id', $s1->series_id)->where('situation', ScheduleSituation::Cancelled->value)->count())->toBe(0);
    });

    it('médico só cancela as sessões dele; agendamento sem série: 404', function () {
        [$s1]      = sssSeries($this);
        $colleague = createDoctorForEntity($this->entity);

        sssAs($this, $colleague->entityUser)->postJson(route('panel.schedules.series.cancel-future', $s1), ['reason' => 'x'])->assertForbidden();

        $single = sssBook($this, '2026-10-06 09:00');
        sssAs($this)->postJson(route('panel.schedules.series.cancel-future', $single), ['reason' => 'x'])->assertNotFound();

        sssAs($this, $this->doctor->entityUser)->postJson(route('panel.schedules.series.cancel-future', $s1), ['reason' => 'Alta'])
            ->assertOk()->assertJsonPath('cancelled', 5);
    });
});
