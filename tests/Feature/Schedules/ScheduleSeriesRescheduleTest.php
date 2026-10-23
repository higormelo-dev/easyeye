<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Exceptions\ScheduleSlotUnavailableException;
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{ClinicResource, Covenant, DoctorWorkSchedule, Entity, ResourceBlock, Schedule, ScheduleBlock, ScheduleSituationLog, User};
use App\Services\Schedules\ScheduleSeriesRescheduler;
use App\Services\{ScheduleSeriesService, ScheduleService};
use Carbon\Carbon;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * iGUT Fase 1 — "Reagendar a série": a sessão escolhida e as seguintes deslocam pelo mesmo intervalo, tudo ou nada.
 * Prévia sem gravar, escrita numa transação (cancelar as antigas ANTES de criar as novas), travas na ordem do módulo
 * (cirurgia → cota do encaixe → série → linhas) e 409 quando a série muda entre a prévia e a trava.
 *
 * Relógio fixo: quinta, 01/10/2026, 07:00. Série padrão: 5 sessões semanais às segundas, 09:00 (05/10 … 02/11).
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

function ssrAs(object $test, $entityUser = null): object
{
    $entityUser ??= $test->secretary;

    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

/**
 * Série criada pela Agenda (como a recepção faz): 1ª sessão em 05/10 09:00 e as `series_dates` seguintes.
 *
 * @return list<Schedule>
 */
function ssrSeries(object $test, array $extra = [], ?array $dates = null): array
{
    ssrAs($test)->postJson(route('panel.schedules.store'), array_merge([
        'doctor_id'    => $test->doctor->id,
        'full_name'    => 'PACIENTE SÉRIE',
        'date_time'    => '2026-10-05 09:00',
        'series_dates' => $dates ?? ['2026-10-12 09:00', '2026-10-19 09:00', '2026-10-26 09:00', '2026-11-02 09:00'],
    ], $extra))->assertCreated();

    return Schedule::query()->where('full_name', 'PACIENTE SÉRIE')->orderBy('date_time')->get()->all();
}

/** Sessão avulsa direto no banco (sem passar pelas regras da Agenda). */
function ssrRow(object $test, string $at, array $extra = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $test->entity->id, 'doctor_id' => $test->doctor->id, 'full_name' => 'OCUPA',
        'date_time' => Carbon::parse($at), 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ], $extra));
}

function ssrPreview(object $test, Schedule $anchor, array $params, $as = null): TestResponse
{
    return ssrAs($test, $as)->getJson(route('panel.schedules.series.reschedule-preview', array_merge(['schedule' => $anchor->id], $params)));
}

function ssrReschedule(object $test, Schedule $anchor, array $body, $as = null): TestResponse
{
    return ssrAs($test, $as)->postJson(route('panel.schedules.series.reschedule', $anchor->id), $body);
}

/** Retrato do banco para provar "nada foi gravado": data, situação, médico e updated_at de cada linha + o histórico. */
function ssrState(): array
{
    return [
        Schedule::query()->orderBy('date_time')->orderBy('id')->get(['id', 'date_time', 'situation', 'doctor_id', 'is_fit_in', 'updated_at'])
            ->map(fn (Schedule $s) => [$s->id, $s->date_time->format('Y-m-d H:i'), $s->situation->value, $s->doctor_id, (bool) $s->is_fit_in, $s->updated_at?->toIso8601String()])->all(),
        ScheduleSituationLog::count(),
    ];
}

/** Datas ('Y-m-d H:i') das sessões da série que NÃO estão canceladas, em ordem. */
function ssrActiveDates(Schedule $any): array
{
    return Schedule::query()->where('series_id', $any->series_id)
        ->where('situation', '!=', ScheduleSituation::Cancelled->value)
        ->orderBy('date_time')->get()
        ->map(fn (Schedule $s) => $s->date_time->format('Y-m-d H:i'))->all();
}

/** Segunda clínica-colega: médico com a mesma escala. */
function ssrColleague(object $test): object
{
    $colleague = createDoctorForEntity($test->entity);

    for ($d = 1; $d <= 6; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $colleague->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $colleague->update(['schedule_interval' => 15]);

    return $colleague;
}

/** Série ABERTA de `$count` sessões semanais futuras às segundas, 09:00 (a 1ª em 05/10/2026), direto no banco. */
function ssrOpenSeries(object $test, int $count): array
{
    $seriesId = (string) Str::uuid();

    return array_map(fn (int $i) => ssrRow($test, Carbon::parse('2026-10-05 09:00')->addWeeks($i)->format('Y-m-d H:i'), [
        'full_name' => 'ABERTA', 'series_id' => $seriesId, 'session_number' => $i + 1, 'session_total' => null,
    ]), range(0, $count - 1));
}

/**
 * Simula "outra pessoa mexeu na série depois da prévia e antes da trava": roda `$change` UMA vez, imediatamente antes de
 * a escrita tomar a trava da série (a pré-leitura já aconteceu; a releitura sob a trava ainda não).
 */
function ssrDisturbAtSeriesLock(Schedule $series, Closure $change): void
{
    $key   = ScheduleSeriesService::seriesLockKey((string) $series->entity_id, (string) $series->series_id);
    $armed = true;

    DB::beforeExecuting(function (string $sql, array $bindings) use ($key, $change, &$armed): void {
        if ($armed && str_contains($sql, 'pg_advisory_xact_lock') && ($bindings[0] ?? null) === $key) {
            $armed = false;
            $change();
        }
    });
}

/** SQL de todas as consultas executadas por `$run`, com os bindings, na ordem. */
function ssrCaptureQueries(Closure $run): array
{
    $log = [];
    DB::listen(function ($query) use (&$log): void {
        $log[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });
    $run();

    return $log;
}

/** Posição da 1ª consulta que satisfaz `$match` (null = não ocorreu). */
function ssrFirstIndex(array $log, Closure $match): ?int
{
    foreach ($log as $index => $entry) {
        if ($match($entry['sql'], $entry['bindings'])) {
            return $index;
        }
    }

    return null;
}

/**
 * Posições (no log de consultas) da trava de cota de cada (médico, horário), na ordem ESTÁVEL das chaves
 * `médico|dia|turno` — a ordem em que as travas têm de acontecer. Posição null = a cota não foi travada.
 *
 * @param list<array{0: string, 1: Carbon}> $slots
 *
 * @return list<int|null>
 */
function ssrQuotaPositionsInStableOrder(array $log, array $slots): array
{
    $byKey = [];

    foreach ($slots as [$doctorId, $date]) {
        $byKey[$doctorId . '|' . $date->toDateString() . '|' . ($date->hour < 13 ? 'morning' : 'afternoon')] = [$doctorId, $date];
    }
    ksort($byKey, SORT_STRING);

    return array_values(array_map(
        fn (array $slot) => ssrFirstIndex($log, fn (string $sql, array $b) => str_contains($sql, 'pg_advisory_xact_lock')
            && ($b[0] ?? null) === ScheduleService::fitInLockKey($slot[0], $slot[1])),
        $byKey,
    ));
}

describe('prévia', function () {
    it('+1 semana: cada sessão com a data nova, sem falso conflito entre a nova de uma e a antiga da seguinte, e sem gravar nada', function () {
        [$s1]   = ssrSeries($this);
        $before = ssrState();

        $res = ssrPreview($this, $s1, ['date_time' => '2026-10-12 09:00'])->assertOk();

        expect($res->json('total'))->toBe(5)
            ->and($res->json('delta_seconds'))->toBe(604800)
            ->and(collect($res->json('sessions'))->pluck('errors')->flatten()->all())->toBe([])
            ->and(collect($res->json('sessions'))->pluck('new_date_time')->all())->toBe(['2026-10-12T09:00', '2026-10-19T09:00', '2026-10-26T09:00', '2026-11-02T09:00', '2026-11-09T09:00'])
            ->and(collect($res->json('sessions'))->pluck('old_date_time')->all())->toBe(['2026-10-05T09:00', '2026-10-12T09:00', '2026-10-19T09:00', '2026-10-26T09:00', '2026-11-02T09:00'])
            ->and($res->json('sessions.0.old_label'))->toBe('05/10/2026 09:00')
            ->and($res->json('sessions.0.new_label'))->toBe('12/10/2026 09:00')
            ->and([$res->json('sessions.2.session_number'), $res->json('sessions.2.session_total')])->toBe([3, 5])
            ->and($res->json('sessions.0.id'))->toBe($s1->id)
            ->and($res->json('sessions.0.doctor_id'))->toBe($this->doctor->id)
            ->and($res->json('sessions.0.is_fit_in'))->toBeFalse();

        expect(ssrState())->toBe($before);
    });

    it('mostra o conflito de cada sessão: outro agendamento, bloqueio do médico, sala bloqueada e fora do expediente', function () {
        [$s1] = ssrSeries($this, ['resource_ids' => [$this->room->id]]);
        ssrRow($this, '2026-10-14 09:00');
        ScheduleBlock::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-21 08:00', 'ends_at' => '2026-10-21 12:00', 'type' => 'holiday']);
        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => '2026-10-28 08:00', 'ends_at' => '2026-10-28 12:00', 'type' => 'other']);

        $errors = collect(ssrPreview($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertOk()->json('sessions'))->pluck('errors', 'new_date_time');

        expect($errors['2026-10-07T09:00'])->toBe([])
            ->and($errors['2026-10-14T09:00'][0])->toBe(__('validation.custom.schedule.doctor_datetime_unique'))
            ->and($errors['2026-10-21T09:00'][0])->toBe(__('actions.work_schedule_blocked'))
            ->and($errors['2026-10-28T09:00'][0])->toBe(__('actions.resource_blocked', ['name' => 'CAMPÍMETRO']))
            ->and($errors['2026-11-04T09:00'])->toBe([]);

        // +6 dias: tudo cai no domingo, fora do expediente.
        $sunday = ssrPreview($this, $s1, ['date_time' => '2026-10-11 09:00'])->assertOk()->json('sessions.0.errors.0');
        expect($sunday)->toBe(__('actions.work_schedule_outside', ['day' => __('actions.weekdays')[0], 'time' => '09:00']));
    });

    it('recusa horário igual ao atual, no passado, sem série, âncora que não é agendada/confirmada e série sem futuras', function () {
        [$s1, $s2] = ssrSeries($this);

        ssrPreview($this, $s1, ['date_time' => '2026-10-05 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('schedules.series_reschedule_same_time'));
        ssrPreview($this, $s1, ['date_time' => '2026-09-30 09:00'])->assertUnprocessable()->assertJsonValidationErrors('date_time');
        ssrPreview($this, $s1, [])->assertUnprocessable()->assertJsonValidationErrors('date_time');

        ssrPreview($this, ssrRow($this, '2026-10-06 09:00'), ['date_time' => '2026-10-07 09:00'])->assertNotFound();

        $s2->update(['situation' => ScheduleSituation::Cancelled->value]);
        ssrPreview($this, $s2, ['date_time' => '2026-10-13 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('schedules.series_reschedule_anchor_not_cancellable'));

        Schedule::query()->where('series_id', $s1->series_id)->update(['situation' => ScheduleSituation::Attended->value]);
        ssrPreview($this, $s1, ['date_time' => '2026-10-06 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('schedules.series_reschedule_no_sessions'));
    });
});

describe('prévia — defesas do service', function () {
    it('o service recusa horário no passado mesmo sem a validação HTTP', function () {
        [$s1] = ssrSeries($this);

        expect(fn () => app(ScheduleSeriesRescheduler::class)->preview($s1, Carbon::parse('2026-09-30 09:00'), null, null, null))
            ->toThrow(ScheduleSlotUnavailableException::class, __('schedules.series_reschedule_past'));
    });

    it('duas sessões do lote que cairiam na mesma data/hora (médicos diferentes) são apontadas como data repetida', function () {
        $colleague      = ssrColleague($this);
        [$s1, $s2, $s3] = ssrSeries($this);
        $s3->update(['doctor_id' => $colleague->id, 'date_time' => $s2->date_time]);   // 12/10 09:00 com outro médico: o índice não barra

        $errors = collect(ssrPreview($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertOk()->json('sessions'))->pluck('errors', 'id');

        expect($errors[$s2->id])->toBe([])->and($errors[$s3->id])->toBe([__('schedules.series_duplicate_date')]);
    });
});

describe('escrita', function () {
    it('"só esta sessão" continua idêntico: move só a escolhida, sem aviso ao paciente', function () {
        [$s1, $s2, $s3] = ssrSeries($this);
        Queue::fake();

        $res = ssrAs($this)->postJson(route('panel.schedules.reschedule', $s3), ['date_time' => '2026-10-20 09:00'])->assertCreated();

        $new = Schedule::query()->findOrFail($res->json('new_schedule.id'));

        expect($res->json('anchor_id'))->toBeNull()
            ->and([$new->session_number, $new->session_total, $new->series_id])->toBe([3, 5, $s1->series_id])
            ->and($s3->fresh()->situation)->toBe(ScheduleSituation::Cancelled)
            ->and($s2->fresh()->date_time->format('Y-m-d H:i'))->toBe('2026-10-12 09:00')
            ->and(ssrActiveDates($s1))->toBe(['2026-10-05 09:00', '2026-10-12 09:00', '2026-10-20 09:00', '2026-10-26 09:00', '2026-11-02 09:00']);

        Queue::assertNotPushed(NotifyScheduleChangeJob::class);
    });

    it('"esta e as seguintes" desloca pelo mesmo intervalo a partir da escolhida; as anteriores, mesmo futuras, ficam; numeração e total preservados', function () {
        [$s1, $s2, $s3, $s4, $s5] = ssrSeries($this);
        Queue::fake();
        Cache::put("waiting_room:{$this->entity->id}", 'velho', 600);

        $res = ssrReschedule($this, $s2, ['date_time' => '2026-10-14 09:00'])->assertCreated();

        expect(Cache::has("waiting_room:{$this->entity->id}"))->toBeFalse();   // a sala de espera recarrega

        $newById = Schedule::query()->whereIn('id', collect($res->json('new_schedules'))->pluck('id'))->get()->keyBy('id');
        $newOf   = fn (Schedule $old) => $newById[collect($res->json('new_schedules'))->firstWhere('old_id', $old->id)['id']];

        expect($res->json('updated'))->toBe(4)
            ->and($res->json('message'))->toBe(trans_choice('schedules.series_rescheduled', 4, ['count' => 4]))
            ->and($res->json('anchor_id'))->toBe($newOf($s2)->id)
            ->and(ssrActiveDates($s1))->toBe(['2026-10-05 09:00', '2026-10-14 09:00', '2026-10-21 09:00', '2026-10-28 09:00', '2026-11-04 09:00'])
            ->and($s1->fresh()->situation)->toBe(ScheduleSituation::Scheduled);

        foreach ([[$s2, 2], [$s3, 3], [$s4, 4], [$s5, 5]] as [$old, $number]) {
            $new  = $newOf($old);
            $note = __('schedules.reschedule_note', ['code' => $new->code]);

            expect($old->fresh()->situation)->toBe(ScheduleSituation::Cancelled)
                ->and($old->fresh()->cancellation_reason)->toBe($note)
                ->and($old->fresh()->date_time->diffInSeconds($new->date_time, false))->toBe(172800.0)
                ->and([$new->session_number, $new->session_total, $new->series_id, $new->situation])->toBe([$number, 5, $s1->series_id, ScheduleSituation::Scheduled])
                ->and([$new->patient_id, $new->full_name, (string) $new->doctor_id])->toBe([$old->patient_id, $old->full_name, (string) $old->doctor_id]);

            $log = ScheduleSituationLog::query()->where('schedule_id', $old->id)->get();
            expect($log)->toHaveCount(1)
                ->and($log[0]->from_situation)->toBe(ScheduleSituation::Scheduled)
                ->and($log[0]->to_situation)->toBe(ScheduleSituation::Cancelled)
                ->and($log[0]->notes)->toBe($note)
                ->and($log[0]->entity_user_id)->toBe($this->secretary->id);
        }

        // Sem aviso ao paciente no lote (o "cancelar as futuras" é outro fluxo e continua avisando).
        Queue::assertNotPushed(NotifyScheduleChangeJob::class);
    });

    it('só move Agendado/Confirmado futuros: passadas, em atendimento, canceladas e faltas ficam com data, situação e histórico intactos', function () {
        $sessions                      = ssrSeries($this, [], ['2026-10-12 09:00', '2026-10-19 09:00', '2026-10-26 09:00', '2026-11-02 09:00', '2026-11-09 09:00']);
        [$s1, $s2, $s3, $s4, $s5, $s6] = $sessions;

        $this->travelTo(Carbon::parse('2026-10-12 08:30:00'));
        $s2->update(['situation' => ScheduleSituation::Waiting->value, 'arrived_at' => now()]);  // hoje 09:00, já chegou
        $s3->update(['situation' => ScheduleSituation::Cancelled->value]);
        $s4->update(['situation' => ScheduleSituation::NoShow->value]);
        $s6->update(['situation' => ScheduleSituation::Confirmed->value]);                        // confirmada também entra

        $this->travelTo(now()->addMinute());
        $untouched = collect([$s1, $s2, $s3, $s4])->mapWithKeys(fn (Schedule $s) => [$s->id => $s->fresh()->only(['date_time', 'situation', 'updated_at', 'cancellation_reason'])])->all();
        $logs      = ScheduleSituationLog::count();

        ssrPreview($this, $s5, ['date_time' => '2026-11-04 09:00'])->assertOk()->assertJsonPath('total', 2);
        $res = ssrReschedule($this, $s5, ['date_time' => '2026-11-04 09:00'])->assertCreated();

        expect($res->json('updated'))->toBe(2);

        foreach ([$s1, $s2, $s3, $s4] as $old) {
            expect($old->fresh()->only(['date_time', 'situation', 'updated_at', 'cancellation_reason']))->toEqual($untouched[$old->id]);
        }

        expect(ScheduleSituationLog::count())->toBe($logs + 2)
            ->and(Schedule::query()->where('series_id', $s1->series_id)->where('situation', ScheduleSituation::Scheduled->value)->pluck('date_time')->map->format('Y-m-d H:i')->sort()->values()->all())
            ->toBe(['2026-10-05 09:00', '2026-11-04 09:00', '2026-11-11 09:00']);   // a passada que ficou agendada + as 2 novas

        // A âncora passada não serve (as futuras existem, mas "a partir desta" não): 422 sem gravar.
        ssrReschedule($this, $s1, ['date_time' => '2026-10-20 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('schedules.series_reschedule_anchor_not_cancellable'));
    });

    it('um conflito em qualquer sessão recusa a série inteira sem gravar nada', function () {
        [$s1] = ssrSeries($this);
        ssrRow($this, '2026-10-21 09:00');   // destino da sessão 3 (+2 dias)

        $this->travelTo(now()->addMinute());
        $before = ssrState();

        ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', [
                'date'   => '21/10/2026 09:00',
                'reason' => __('validation.custom.schedule.doctor_datetime_unique'),
            ]));

        expect(ssrState())->toBe($before);
    });

    it('a escrita revalida: conflito que nasce DEPOIS da prévia recusa e nada é gravado (agendamento, bloqueio e sala)', function () {
        [$s1] = ssrSeries($this, ['resource_ids' => [$this->room->id]]);

        ssrPreview($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertOk()
            ->assertJsonPath('sessions.2.errors', []);

        $this->travelTo(now()->addMinute());
        $before = ssrState();

        // 1) outro agendamento no destino; 2) bloqueio do médico; 3) sala bloqueada — um de cada vez.
        $occupant = ssrRow($this, '2026-10-14 09:00');
        ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertUnprocessable()->assertJsonValidationErrors('series_dates');
        $occupant->forceDelete();

        $block = ScheduleBlock::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-21 08:00', 'ends_at' => '2026-10-21 12:00', 'type' => 'holiday']);
        ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', ['date' => '21/10/2026 09:00', 'reason' => __('actions.work_schedule_blocked')]));
        $block->delete();

        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => '2026-10-28 08:00', 'ends_at' => '2026-10-28 12:00', 'type' => 'other']);
        ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', ['date' => '28/10/2026 09:00', 'reason' => __('actions.resource_blocked', ['name' => 'CAMPÍMETRO'])]));

        expect(ssrState())->toBe($before);
    });

    it('+1 semana numa série semanal: as 5 antigas viram Cancelado, as 5 novas nascem Agendado e nada viola o índice único', function () {
        [$s1] = ssrSeries($this);

        $res = ssrReschedule($this, $s1, ['date_time' => '2026-10-12 09:00'])->assertCreated();

        $active = Schedule::query()->where('series_id', $s1->series_id)->where('situation', ScheduleSituation::Scheduled->value)->orderBy('date_time')->get();

        expect($res->json('updated'))->toBe(5)
            ->and($active->map->date_time->map->format('Y-m-d H:i')->all())->toBe(['2026-10-12 09:00', '2026-10-19 09:00', '2026-10-26 09:00', '2026-11-02 09:00', '2026-11-09 09:00'])
            ->and($active->pluck('session_number')->all())->toBe([1, 2, 3, 4, 5])
            ->and(Schedule::query()->where('series_id', $s1->series_id)->where('situation', ScheduleSituation::Cancelled->value)->count())->toBe(5)
            ->and($res->json('anchor_id'))->toBe($active[0]->id);
    });

    it('adiantar a série também vale (delta negativo, ainda no futuro)', function () {
        [, $s2] = ssrSeries($this);

        ssrReschedule($this, $s2, ['date_time' => '2026-10-08 09:00'])->assertCreated()->assertJsonPath('updated', 4);

        expect(ssrActiveDates($s2))->toBe(['2026-10-05 09:00', '2026-10-08 09:00', '2026-10-15 09:00', '2026-10-22 09:00', '2026-10-29 09:00']);
    });

    it('data igual à atual, no passado ou sem a data: 422 e nada gravado; agendamento sem série: 404', function () {
        [$s1] = ssrSeries($this);
        $this->travelTo(now()->addMinute());
        $before = ssrState();

        ssrReschedule($this, $s1, ['date_time' => '2026-10-05 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('schedules.series_reschedule_same_time'));
        ssrReschedule($this, $s1, ['date_time' => '2026-09-30 09:00'])->assertUnprocessable()->assertJsonValidationErrors('date_time');
        ssrReschedule($this, $s1, [])->assertUnprocessable()->assertJsonValidationErrors('date_time');
        ssrReschedule($this, ssrRow($this, '2026-10-06 09:00'), ['date_time' => '2026-10-07 09:00'])->assertNotFound();

        expect(ssrState()[0])->toHaveCount(count($before[0]) + 1)
            ->and(ssrState()[1])->toBe($before[1]);
    });

    it('a sala acompanha: as sessões novas reservam a mesma sala, mesmo quando a data nova é a antiga da seguinte (+1 semana)', function () {
        [$s1] = ssrSeries($this, ['resource_ids' => [$this->room->id]]);

        $res = ssrReschedule($this, $s1, ['date_time' => '2026-10-12 09:00'])->assertCreated();

        $new = Schedule::query()->whereIn('id', collect($res->json('new_schedules'))->pluck('id'))->get();

        expect($new)->toHaveCount(5)
            ->and($new->every(fn (Schedule $s) => $s->resources()->pluck('clinic_resources.id')->all() === [$this->room->id]))->toBeTrue();
    });

    it('série fechada preserva "n/N"; série aberta (sem total) preserva o número e continua sem total', function () {
        [, , $s3] = ssrSeries($this);

        $closed = ssrReschedule($this, $s3, ['date_time' => '2026-10-20 09:00'])->assertCreated();
        $rows   = Schedule::query()->whereIn('id', collect($closed->json('new_schedules'))->pluck('id'))->orderBy('date_time')->get();
        expect($rows->pluck('session_number')->all())->toBe([3, 4, 5])->and($rows->pluck('session_total')->unique()->all())->toBe([5]);

        $seriesId = (string) Str::uuid();
        $open     = collect([[1, '2026-10-05'], [2, '2026-10-12'], [3, '2026-10-19']])->map(fn (array $row) => ssrRow($this, $row[1] . ' 10:00', [
            'full_name' => 'ABERTA', 'series_id' => $seriesId, 'session_number' => $row[0], 'session_total' => null,
        ]));

        $res  = ssrReschedule($this, $open[1], ['date_time' => '2026-10-13 10:00'])->assertCreated();
        $rows = Schedule::query()->whereIn('id', collect($res->json('new_schedules'))->pluck('id'))->orderBy('date_time')->get();

        expect($rows->pluck('session_number')->all())->toBe([2, 3])
            ->and($rows->pluck('session_total')->all())->toBe([null, null]);
    });

    it('convênio inativado em qualquer sessão: a prévia avisa e a escrita recusa (covenant_id) sem gravar; reativado, passa', function () {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED TESTE']);
        [$s1]     = ssrSeries($this);
        Schedule::query()->where('series_id', $s1->series_id)->update(['covenant_id' => $covenant->id]);
        $covenant->update(['active' => false]);

        $message = __('validation.custom.covenant.reschedule_inactive', ['name' => 'UNIMED TESTE']);
        $this->travelTo(now()->addMinute());
        $before = ssrState();

        expect(collect(ssrPreview($this, $s1, ['date_time' => '2026-10-06 09:00'])->assertOk()->json('sessions'))->every(fn (array $s) => in_array($message, $s['errors'], true)))->toBeTrue();

        ssrReschedule($this, $s1, ['date_time' => '2026-10-06 09:00'])->assertUnprocessable()->assertJsonPath('errors.covenant_id.0', $message);
        expect(ssrState())->toBe($before);

        $covenant->update(['active' => true]);
        ssrReschedule($this, $s1, ['date_time' => '2026-10-06 09:00'])->assertCreated()->assertJsonPath('updated', 5);
    });
});

describe('perfis, clínicas e médico', function () {
    it('perfil médico só move as sessões dele; o colega e outra clínica não alcançam a série', function () {
        $colleague = ssrColleague($this);
        $sessions  = ssrSeries($this);
        $sessions[2]->update(['doctor_id' => $colleague->id]);   // a sessão 3 é do colega

        ssrPreview($this, $sessions[0], ['date_time' => '2026-10-07 09:00'], $colleague->entityUser)->assertForbidden();
        ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-07 09:00'], $colleague->entityUser)->assertForbidden();

        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherEu = createEntityUser($other, User::factory()->create(), ClientRule::Admin->value);
        ssrPreview($this, $sessions[0], ['date_time' => '2026-10-07 09:00'], $otherEu)->assertNotFound();
        ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-07 09:00'], $otherEu)->assertNotFound();

        // O médico dono move as dele (1, 2, 4, 5); a do colega não entra nem é validada.
        ssrPreview($this, $sessions[0], ['date_time' => '2026-10-07 09:00'], $this->doctor->entityUser)->assertOk()->assertJsonPath('total', 4);
        ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-07 09:00'], $this->doctor->entityUser)->assertCreated()->assertJsonPath('updated', 4);

        expect($sessions[2]->fresh()->date_time->format('Y-m-d H:i'))->toBe('2026-10-19 09:00')
            ->and($sessions[2]->fresh()->situation)->toBe(ScheduleSituation::Scheduled);
    });

    it('a recepção move a série inteira, de médicos diferentes, e cada sessão mantém o seu médico', function () {
        $colleague = ssrColleague($this);
        $sessions  = ssrSeries($this);
        $sessions[1]->update(['doctor_id' => $colleague->id]);

        $res = ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-06 09:00'])->assertCreated()->assertJsonPath('updated', 5);

        expect(collect($res->json('new_schedules'))->pluck('doctor_id')->all())
            ->toBe([$this->doctor->id, $colleague->id, $this->doctor->id, $this->doctor->id, $this->doctor->id]);
    });

    it('com doctor_id no payload, TODAS as sessões passam para o médico escolhido e a prévia usa a agenda dele', function () {
        $colleague = ssrColleague($this);
        [$s1]      = ssrSeries($this);
        ssrRow($this, '2026-10-13 09:00', ['doctor_id' => $colleague->id]);   // o colega está ocupado no destino da sessão 2

        $preview = collect(ssrPreview($this, $s1, ['date_time' => '2026-10-06 09:00', 'doctor_id' => $colleague->id])->assertOk()->json('sessions'));
        expect($preview[1]['errors'][0])->toBe(__('validation.custom.schedule.doctor_datetime_unique'))
            ->and($preview->pluck('doctor_id')->unique()->all())->toBe([$colleague->id]);

        ssrReschedule($this, $s1, ['date_time' => '2026-10-06 09:00', 'doctor_id' => $colleague->id])->assertUnprocessable();

        Schedule::query()->where('full_name', 'OCUPA')->forceDelete();
        $res = ssrReschedule($this, $s1, ['date_time' => '2026-10-06 09:00', 'doctor_id' => $colleague->id])->assertCreated();

        expect(collect($res->json('new_schedules'))->pluck('doctor_id')->unique()->all())->toBe([$colleague->id]);
    });

    it('médico de outra clínica no doctor_id: 422 sem gravar', function () {
        [$s1]    = ssrSeries($this);
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = createDoctorForEntity($other);
        $this->travelTo(now()->addMinute());
        $before = ssrState();

        ssrPreview($this, $s1, ['date_time' => '2026-10-06 09:00', 'doctor_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('doctor_id');
        ssrReschedule($this, $s1, ['date_time' => '2026-10-06 09:00', 'doctor_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('doctor_id');

        expect(ssrState())->toBe($before);
    });
});

describe('encaixe', function () {
    it('o teto do turno vale em cada destino (Faltou também ocupa a cota) e o lote inteiro recusa', function () {
        $this->doctor->update(['fit_in_limit_morning' => 1]);
        [$s1]     = ssrSeries($this, ['is_fit_in' => true]);
        $occupant = ssrRow($this, '2026-10-14 10:00', ['is_fit_in' => true, 'situation' => ScheduleSituation::NoShow->value]);

        $reason = trans_choice('schedules.fit_in_limit_reached', 1, ['limit' => 1, 'shift' => __('schedules.shift_morning'), 'date' => '14/10/2026']);

        $errors = collect(ssrPreview($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertOk()->json('sessions'))->pluck('errors', 'new_date_time');
        expect($errors['2026-10-14T09:00'])->toBe([$reason])->and($errors['2026-10-07T09:00'])->toBe([]);

        $this->travelTo(now()->addMinute());
        $before = ssrState();
        ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', ['date' => '14/10/2026 09:00', 'reason' => $reason]));
        expect(ssrState())->toBe($before);

        $occupant->forceDelete();
        $res = ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertCreated();
        expect(Schedule::query()->whereIn('id', collect($res->json('new_schedules'))->pluck('id'))->pluck('is_fit_in')->unique()->all())->toBe([true]);
    });

    it('duas sessões do lote que caem no mesmo dia e turno somam na cota (prévia e escrita), e a cota maior deixa passar', function () {
        $this->doctor->update(['fit_in_limit_morning' => 1]);
        $seriesId = (string) Str::uuid();
        $first    = ssrRow($this, '2026-10-05 09:00', ['full_name' => 'DUPLA', 'is_fit_in' => true, 'series_id' => $seriesId, 'session_number' => 1, 'session_total' => 2]);
        ssrRow($this, '2026-10-05 10:00', ['full_name' => 'DUPLA', 'is_fit_in' => true, 'series_id' => $seriesId, 'session_number' => 2, 'session_total' => 2]);

        $reason  = trans_choice('schedules.fit_in_limit_reached', 1, ['limit' => 1, 'shift' => __('schedules.shift_morning'), 'date' => '12/10/2026']);
        $preview = collect(ssrPreview($this, $first, ['date_time' => '2026-10-12 09:00'])->assertOk()->json('sessions'));

        expect($preview[0]['errors'])->toBe([])->and($preview[1]['errors'])->toBe([$reason]);

        ssrReschedule($this, $first, ['date_time' => '2026-10-12 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', ['date' => '12/10/2026 10:00', 'reason' => $reason]));
        expect(Schedule::query()->where('full_name', 'DUPLA')->where('situation', ScheduleSituation::Cancelled->value)->count())->toBe(0);

        $this->doctor->update(['fit_in_limit_morning' => 2]);
        ssrReschedule($this, $first, ['date_time' => '2026-10-12 09:00'])->assertCreated()->assertJsonPath('updated', 2);
    });

    it('série de encaixes +1 semana (teto 1 por turno): a data nova de uma é a antiga da seguinte e não conta contra o teto', function () {
        $this->doctor->update(['fit_in_limit_morning' => 1]);
        [$s1] = ssrSeries($this, ['is_fit_in' => true]);

        expect(collect(ssrPreview($this, $s1, ['date_time' => '2026-10-12 09:00'])->assertOk()->json('sessions'))->pluck('errors')->flatten()->all())->toBe([]);

        $res = ssrReschedule($this, $s1, ['date_time' => '2026-10-12 09:00'])->assertCreated();

        expect($res->json('updated'))->toBe(5)
            ->and(Schedule::query()->where('series_id', $s1->series_id)->where('situation', '!=', ScheduleSituation::Cancelled->value)->where('is_fit_in', true)->count())->toBe(5);
    });

    it('a conferência final do teto, no INSERT, pega o encaixe que entrou por fora das travas depois da validação', function () {
        $this->doctor->update(['fit_in_limit_morning' => 1]);
        [$s1] = ssrSeries($this, ['is_fit_in' => true]);
        $this->travelTo(now()->addMinute());
        $before = ssrState();

        // Um escritor que não passa pela cota entra no turno do destino da sessão 2 (14/10) antes do 1º INSERT do lote.
        $armed = true;
        DB::beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with($sql, 'insert into "schedules"')) {
                $armed = false;
                DB::table('schedules')->insert([
                    'id'        => (string) Str::uuid(), 'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id,
                    'code'      => 'SDL-9999999999', 'full_name' => 'POR FORA', 'date_time' => '2026-10-14 10:30:00',
                    'situation' => ScheduleSituation::Scheduled->value, 'active' => true, 'cellphone_whatsapp' => false,
                    'is_fit_in' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.series_dates.0', __('schedules.series_conflict', [
                'date'   => '14/10/2026 09:00',
                'reason' => trans_choice('schedules.fit_in_limit_reached', 1, ['limit' => 1, 'shift' => __('schedules.shift_morning'), 'date' => '14/10/2026']),
            ]));

        expect(ssrState())->toBe($before);   // o INSERT por fora também foi desfeito (mesma transação)
    });

    it('is_fit_in no payload vale para todas as sessões (true e false); ausente mantém cada uma', function () {
        $this->doctor->update(['fit_in_limit_morning' => 5]);
        [$s1] = ssrSeries($this);

        $asFitIn = ssrReschedule($this, $s1, ['date_time' => '2026-10-06 09:00', 'is_fit_in' => true])->assertCreated();
        expect(collect($asFitIn->json('new_schedules'))->pluck('is_fit_in')->unique()->all())->toBe([true]);

        $newAnchor = Schedule::query()->findOrFail($asFitIn->json('anchor_id'));
        $kept      = ssrReschedule($this, $newAnchor, ['date_time' => '2026-10-07 09:00'])->assertCreated();
        expect(collect($kept->json('new_schedules'))->pluck('is_fit_in')->unique()->all())->toBe([true]);

        $anchor    = Schedule::query()->findOrFail($kept->json('anchor_id'));
        $asRegular = ssrReschedule($this, $anchor, ['date_time' => '2026-10-08 09:00', 'is_fit_in' => false])->assertCreated();
        expect(collect($asRegular->json('new_schedules'))->pluck('is_fit_in')->unique()->all())->toBe([false]);
    });
});

describe('payloads da Agenda e do drawer', function () {
    it('o detalhe traz series_id, series_future_count e as URLs de cada sessão; o count segue a régua do lote', function () {
        [$s1, $s2, $s3, $s4, $s5] = ssrSeries($this);
        $s2->update(['situation' => ScheduleSituation::Cancelled->value]);
        $s5->update(['situation' => ScheduleSituation::Attended->value]);

        $rows = collect(ssrAs($this)->getJson(route('panel.schedules.show', $s3))->assertOk()->json('data.series'))->keyBy('id');

        expect($rows[$s1->id]['series_future_count'])->toBe(3)    // s1, s3, s4
            ->and($rows[$s2->id]['series_future_count'])->toBe(0)  // cancelada
            ->and($rows[$s3->id]['series_future_count'])->toBe(2)
            ->and($rows[$s4->id]['series_future_count'])->toBe(1)
            ->and($rows[$s5->id]['series_future_count'])->toBe(0)  // atendida
            ->and($rows[$s3->id]['series_id'])->toBe($s1->series_id)
            ->and($rows[$s3->id]['reschedule_series_url'])->toBe(route('panel.schedules.series.reschedule', $s3->id))
            ->and($rows[$s3->id]['reschedule_series_preview_url'])->toBe(route('panel.schedules.series.reschedule-preview', $s3->id));

        // Sessão que já passou (mesmo agendada) não é elegível: o count dela é 0 e não entra na conta das outras.
        $this->travelTo(Carbon::parse('2026-10-12 08:30:00'));
        $later = collect(ssrAs($this)->getJson(route('panel.schedules.show', $s3))->assertOk()->json('data.series'))->keyBy('id');

        expect($later[$s1->id]['series_future_count'])->toBe(0)    // 05/10, já passou
            ->and($later[$s3->id]['series_future_count'])->toBe(2);
    });

    it('a linha da Agenda traz o count e as URLs; agendamento solto e sessão passada trazem 0/null; uma só consulta para as séries da página', function () {
        [$s1] = ssrSeries($this);
        ssrRow($this, '2026-10-05 10:00', ['full_name' => 'SOLTO']);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"series_id" in')) {
                $queries[] = $query->sql;
            }
        });

        $items = collect(ssrAs($this)->get(route('panel.schedules.index', ['date' => '2026-10-05']))
            ->assertOk()->viewData('page')['props']['scheduleItems'])->keyBy('id');

        expect($items[$s1->id]['series_future_count'])->toBe(5)
            ->and($items[$s1->id]['reschedule_series_url'])->toBe(route('panel.schedules.series.reschedule', $s1->id))
            ->and($items[$s1->id]['reschedule_series_preview_url'])->toBe(route('panel.schedules.series.reschedule-preview', $s1->id))
            ->and(collect($items)->firstWhere('name', 'SOLTO')['series_future_count'])->toBe(0)
            ->and(collect($items)->firstWhere('name', 'SOLTO')['reschedule_series_url'])->toBeNull()
            ->and($queries)->toHaveCount(1);

        $this->travelTo(Carbon::parse('2026-10-05 10:30:00'));
        $past = collect(ssrAs($this)->get(route('panel.schedules.index', ['date' => '2026-10-05']))->assertOk()->viewData('page')['props']['scheduleItems'])->keyBy('id');
        expect($past[$s1->id]['series_future_count'])->toBe(0);
    });

    it('perfil médico: o count só conta as sessões dele', function () {
        $colleague = ssrColleague($this);
        $sessions  = ssrSeries($this);
        $sessions[2]->update(['doctor_id' => $colleague->id]);

        $asDoctor = collect(ssrAs($this, $this->doctor->entityUser)->getJson(route('panel.schedules.show', $sessions[0]))->assertOk()->json('data.series'))->keyBy('id');
        $asStaff  = collect(ssrAs($this)->getJson(route('panel.schedules.show', $sessions[0]))->assertOk()->json('data.series'))->keyBy('id');

        expect($asDoctor)->toHaveCount(4)->and($asDoctor[$sessions[0]->id]['series_future_count'])->toBe(4)
            ->and($asStaff[$sessions[0]->id]['series_future_count'])->toBe(5);

        // Na lista da Agenda do médico o count também é só das sessões dele.
        $items = collect(ssrAs($this, $this->doctor->entityUser)->get(route('panel.schedules.index', ['date' => '2026-10-05']))->assertOk()->viewData('page')['props']['scheduleItems'])->keyBy('id');
        expect($items[$sessions[0]->id]['series_future_count'])->toBe(4);
    });
});

describe('teto de sessões do lote', function () {
    it('61 sessões a mover: a prévia e a escrita recusam com o teto na mensagem, sem tomar trava nem gravar nada', function () {
        $sessions = ssrOpenSeries($this, ScheduleSeriesService::MAX_SESSIONS + 1);
        $this->travelTo(now()->addMinute());
        $before  = ssrState();
        $message = __('schedules.series_reschedule_too_many', ['max' => ScheduleSeriesService::MAX_SESSIONS]);

        ssrPreview($this, $sessions[0], ['date_time' => '2026-10-06 09:00'])->assertUnprocessable()->assertJsonPath('errors.date_time.0', $message);

        $log = ssrCaptureQueries(fn () => ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-06 09:00'])->assertUnprocessable()->assertJsonPath('errors.date_time.0', $message));

        expect($message)->toContain('60')
            ->and(ssrState())->toBe($before)
            // Recusou ANTES de qualquer trava, e a consulta do lote é limitada (não carrega a série inteira).
            ->and(ssrFirstIndex($log, fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock')))->toBeNull()
            ->and(ssrFirstIndex($log, fn (string $sql) => str_contains($sql, 'from "schedules"') && str_contains($sql, 'limit ' . (ScheduleSeriesService::MAX_SESSIONS + 1))))->not->toBeNull();
    });

    it('60 sessões a mover passam (prévia e escrita); o teto é do LOTE a partir da escolhida, não do tamanho da série', function () {
        $sessions = ssrOpenSeries($this, ScheduleSeriesService::MAX_SESSIONS + 1);

        // A partir da sessão 2 sobram exatamente 60.
        ssrPreview($this, $sessions[1], ['date_time' => '2026-10-13 09:00'])->assertOk()->assertJsonPath('total', 60);
        ssrReschedule($this, $sessions[1], ['date_time' => '2026-10-13 09:00'])->assertCreated()->assertJsonPath('updated', 60);

        expect($sessions[0]->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(Schedule::query()->where('series_id', $sessions[0]->series_id)->where('situation', ScheduleSituation::Scheduled->value)->count())->toBe(61);
    });

    it('a prévia tem limite de requisições por usuário (429 depois de 60 por minuto)', function () {
        [$s1] = ssrSeries($this);

        foreach (range(1, 60) as $i) {
            ssrPreview($this, $s1, ['date_time' => '2026-10-06 09:00'])->assertOk();
        }

        ssrPreview($this, $s1, ['date_time' => '2026-10-06 09:00'])->assertStatus(429);
    });
});

describe('série alterada entre a prévia e a trava', function () {
    it('sessão nova ou religada, âncora movida, ou sessão do lote com outra data/médico/encaixe: 409 e nada é gravado', function (string $change) {
        $colleague = ssrColleague($this);
        $sessions  = ssrSeries($this);
        $sessions[4]->update(['situation' => ScheduleSituation::Cancelled->value]);   // fora do lote na pré-leitura
        $this->travelTo(now()->addMinute());
        $before = ssrState();

        $disturb = [
            'sessão religada'           => fn () => DB::table('schedules')->where('id', $sessions[4]->id)->update(['situation' => ScheduleSituation::Scheduled->value]),
            'âncora movida'             => fn () => DB::table('schedules')->where('id', $sessions[0]->id)->update(['date_time' => '2026-10-05 09:15:00']),
            'outra data numa do lote'   => fn () => DB::table('schedules')->where('id', $sessions[2]->id)->update(['date_time' => '2026-10-19 09:30:00']),
            'outro médico numa do lote' => fn () => DB::table('schedules')->where('id', $sessions[2]->id)->update(['doctor_id' => $colleague->id]),
            'virou encaixe'             => fn () => DB::table('schedules')->where('id', $sessions[2]->id)->update(['is_fit_in' => true]),
        ][$change];

        ssrDisturbAtSeriesLock($sessions[0], $disturb);

        ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-07 09:00'])->assertStatus(409)
            ->assertJsonPath('message', __('schedules.series_reschedule_changed'));

        // Só o que a "outra pessoa" alterou mudou: o lote não cancelou nada, não criou sessão nem histórico.
        expect(ScheduleSituationLog::count())->toBe($before[1])
            ->and(Schedule::count())->toBe(count($before[0]))
            ->and(Schedule::query()->where('situation', ScheduleSituation::Cancelled->value)->count())
            ->toBeLessThanOrEqual(collect($before[0])->where(2, ScheduleSituation::Cancelled->value)->count());
    })->with(['sessão religada', 'âncora movida', 'outra data numa do lote', 'outro médico numa do lote', 'virou encaixe']);

    it('sessão que só SAIU do lote (cancelada por outra pessoa) não trava: o lote segue com as que restaram', function () {
        $sessions = ssrSeries($this);

        ssrDisturbAtSeriesLock($sessions[0], fn () => DB::table('schedules')->where('id', $sessions[2]->id)->update(['situation' => ScheduleSituation::Cancelled->value]));

        ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-07 09:00'])->assertCreated()->assertJsonPath('updated', 4);

        expect($sessions[2]->fresh()->date_time->format('Y-m-d H:i'))->toBe('2026-10-19 09:00')
            ->and(ssrActiveDates($sessions[0]))->toBe(['2026-10-07 09:00', '2026-10-14 09:00', '2026-10-28 09:00', '2026-11-04 09:00']);
    });

    it('âncora cancelada por outra pessoa no meio: 422 (só agendadas ou confirmadas), nada gravado', function () {
        $sessions = ssrSeries($this);

        ssrDisturbAtSeriesLock($sessions[0], fn () => DB::table('schedules')->where('id', $sessions[0]->id)->update(['situation' => ScheduleSituation::Cancelled->value]));

        ssrReschedule($this, $sessions[0], ['date_time' => '2026-10-07 09:00'])->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('schedules.series_reschedule_anchor_not_cancellable'));

        // A mexida simulada rodou DENTRO da transação do lote, que desfez tudo: nenhuma sessão cancelada, nenhum histórico.
        expect(ScheduleSituationLog::count())->toBe(0)
            ->and(Schedule::count())->toBe(5)
            ->and(Schedule::query()->where('situation', ScheduleSituation::Cancelled->value)->count())->toBe(0);
    });

    it('o "só esta sessão" que esperou o lote não cria uma 2ª sessão: 409 se a sessão foi cancelada ou movida na espera', function (string $change) {
        $sessions = ssrSeries($this);
        $changes  = [
            'cancelada' => ['situation' => ScheduleSituation::Cancelled->value],
            'movida'    => ['date_time' => '2026-10-19 09:30:00'],
        ];

        ssrDisturbAtSeriesLock($sessions[2], fn () => DB::table('schedules')->where('id', $sessions[2]->id)->update($changes[$change]));

        ssrAs($this)->postJson(route('panel.schedules.reschedule', $sessions[2]), ['date_time' => '2026-10-20 09:00'])
            ->assertStatus(409)->assertJsonPath('message', __('schedules.series_reschedule_changed'));

        expect(Schedule::count())->toBe(5);
    })->with(['cancelada', 'movida']);
});

describe('travas', function () {
    it('ordem: cirurgia → cota do encaixe → série → linhas FOR UPDATE → cancelar → criar', function () {
        $this->doctor->update(['fit_in_limit_morning' => 5]);
        [$s1] = ssrSeries($this, ['is_fit_in' => true]);

        $log = ssrCaptureQueries(fn () => ssrReschedule($this, $s1, ['date_time' => '2026-10-07 09:00'])->assertCreated());

        $seriesKey = ScheduleSeriesService::seriesLockKey((string) $s1->entity_id, (string) $s1->series_id);
        $quotaKey  = ScheduleService::fitInLockKey((string) $this->doctor->id, Carbon::parse('2026-10-07 09:00'));

        $positions = [
            'cirurgia' => ssrFirstIndex($log, fn (string $sql) => str_contains($sql, 'from "doctors"') && str_contains($sql, 'for share')),
            'cota'     => ssrFirstIndex($log, fn (string $sql, array $b) => str_contains($sql, 'pg_advisory_xact_lock') && ($b[0] ?? null) === $quotaKey),
            'série'    => ssrFirstIndex($log, fn (string $sql, array $b) => str_contains($sql, 'pg_advisory_xact_lock') && ($b[0] ?? null) === $seriesKey),
            'linhas'   => ssrFirstIndex($log, fn (string $sql) => str_contains($sql, 'from "schedules"') && str_contains($sql, 'for update')),
            'cancelar' => ssrFirstIndex($log, fn (string $sql) => str_starts_with($sql, 'update "schedules"')),
            'criar'    => ssrFirstIndex($log, fn (string $sql) => str_starts_with($sql, 'insert into "schedules"')),
        ];

        expect(array_filter($positions, fn ($p) => $p === null))->toBe([])
            ->and(array_values($positions))->toBe(collect($positions)->sort()->values()->all())
            ->and(count(array_unique($positions)))->toBe(6)
            // A âncora e as linhas do lote são travadas (duas consultas FOR UPDATE nas sessões).
            ->and(collect($log)->filter(fn (array $q) => str_contains($q['sql'], 'from "schedules"') && str_contains($q['sql'], 'for update'))->count())->toBe(2);

        // Todos os cancelamentos vêm antes do primeiro INSERT (libera o índice único do médico).
        $lastCancel = collect($log)->keys()->filter(fn (int $i) => str_starts_with($log[$i]['sql'], 'update "schedules"') && str_contains($log[$i]['sql'], '"situation"'))->last();
        expect($lastCancel)->not->toBeNull()->and($positions['criar'])->toBeGreaterThan($lastCancel);
    });

    it('o lote trava as cotas dos encaixes em ordem estável (médico, dia, turno), não na ordem das datas', function () {
        $this->doctor->update(['fit_in_limit_morning' => 5]);
        $colleague = ssrColleague($this);
        $colleague->update(['fit_in_limit_morning' => 5]);

        // Médicos alternados: pela data a ordem seria A, B, A; pela chave estável os dois do mesmo médico ficam juntos.
        $seriesId = (string) Str::uuid();
        $rows     = [
            ssrRow($this, '2026-10-05 09:00', ['full_name' => 'MISTA', 'is_fit_in' => true, 'series_id' => $seriesId, 'session_number' => 1, 'session_total' => 3, 'doctor_id' => $this->doctor->id]),
            ssrRow($this, '2026-10-12 09:00', ['full_name' => 'MISTA', 'is_fit_in' => true, 'series_id' => $seriesId, 'session_number' => 2, 'session_total' => 3, 'doctor_id' => $colleague->id]),
            ssrRow($this, '2026-10-19 09:00', ['full_name' => 'MISTA', 'is_fit_in' => true, 'series_id' => $seriesId, 'session_number' => 3, 'session_total' => 3, 'doctor_id' => $this->doctor->id]),
        ];

        $log = ssrCaptureQueries(fn () => ssrReschedule($this, $rows[0], ['date_time' => '2026-10-06 09:00'])->assertCreated());

        $positions = ssrQuotaPositionsInStableOrder($log, [
            [$this->doctor->id, Carbon::parse('2026-10-06 09:00')],
            [$colleague->id, Carbon::parse('2026-10-13 09:00')],
            [$this->doctor->id, Carbon::parse('2026-10-20 09:00')],
        ]);

        expect(array_filter($positions, fn ($p) => $p === null))->toBe([])
            ->and($positions)->toHaveCount(3)
            ->and($positions)->toBe(collect($positions)->sort()->values()->all());
    });

    it('store() de série de encaixe trava TODAS as cotas (1ª data e extras, manhã e tarde, em ordem estável) ANTES de qualquer INSERT e da trava do código', function () {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => 1, 'starts_at' => '14:00:00', 'ends_at' => '17:00:00']);
        $this->doctor->update(['fit_in_limit_morning' => 5, 'fit_in_limit_afternoon' => 5]);

        // Extras fora de ordem de propósito: a ordem das travas é a estável (médico, dia, turno), não a do pedido.
        $log = ssrCaptureQueries(fn () => ssrAs($this)->postJson(route('panel.schedules.store'), [
            'doctor_id' => $this->doctor->id, 'full_name' => 'PACIENTE SÉRIE', 'date_time' => '2026-10-05 09:00',
            'is_fit_in' => true, 'series_dates' => ['2026-10-19 09:00', '2026-10-12 09:00', '2026-10-05 14:00'],
        ])->assertCreated());

        $quotas = ssrQuotaPositionsInStableOrder($log, [
            [$this->doctor->id, Carbon::parse('2026-10-05 09:00')],
            [$this->doctor->id, Carbon::parse('2026-10-05 14:00')],   // mesmo dia, outro turno: OUTRA cota
            [$this->doctor->id, Carbon::parse('2026-10-12 09:00')],
            [$this->doctor->id, Carbon::parse('2026-10-19 09:00')],
        ]);
        $surgery = ssrFirstIndex($log, fn (string $sql) => str_contains($sql, 'from "doctors"') && str_contains($sql, 'for share'));
        $code    = ssrFirstIndex($log, fn (string $sql, array $b) => str_contains($sql, 'pg_advisory_xact_lock') && ($b[0] ?? null) === Schedule::codeLockKey((string) $this->entity->id));
        $insert  = ssrFirstIndex($log, fn (string $sql) => str_starts_with($sql, 'insert into "schedules"'));

        expect(array_filter([$surgery, $code, $insert, ...$quotas], fn ($p) => $p === null))->toBe([])
            ->and($quotas)->toHaveCount(4)
            // cirurgia → as 4 cotas (em ordem estável) → código SDL → INSERT
            ->and([$surgery, ...$quotas, $code, $insert])->toBe(collect([$surgery, ...$quotas, $code, $insert])->sort()->values()->all())
            ->and(count(array_unique([$surgery, ...$quotas, $code, $insert])))->toBe(7);
    });

    it('o "só esta sessão" de uma sessão de série toma a trava da série; agendamento solto não', function () {
        [, , $s3] = ssrSeries($this);
        $solo     = ssrRow($this, '2026-10-06 09:00');

        $key  = ScheduleSeriesService::seriesLockKey((string) $s3->entity_id, (string) $s3->series_id);
        $took = fn (array $log) => ssrFirstIndex($log, fn (string $sql, array $b) => str_contains($sql, 'pg_advisory_xact_lock') && ($b[0] ?? null) === $key);

        $series = ssrCaptureQueries(fn () => ssrAs($this)->postJson(route('panel.schedules.reschedule', $s3), ['date_time' => '2026-10-20 09:00'])->assertCreated());
        $loose  = ssrCaptureQueries(fn () => ssrAs($this)->postJson(route('panel.schedules.reschedule', $solo), ['date_time' => '2026-10-07 10:00'])->assertCreated());

        expect($took($series))->not->toBeNull()
            ->and(collect($loose)->contains(fn (array $q) => str_contains($q['sql'], 'pg_advisory_xact_lock') && ($q['bindings'][0] ?? null) === $key))->toBeFalse();
    });

    it('"cancelar as futuras" toma a trava da série antes de ler as sessões', function () {
        [$s1] = ssrSeries($this);
        $key  = ScheduleSeriesService::seriesLockKey((string) $s1->entity_id, (string) $s1->series_id);

        $log = ssrCaptureQueries(fn () => ssrAs($this)->postJson(route('panel.schedules.series.cancel-future', $s1), ['reason' => 'Alta'])->assertOk());

        $lock = ssrFirstIndex($log, fn (string $sql, array $b) => str_contains($sql, 'pg_advisory_xact_lock') && ($b[0] ?? null) === $key);
        $read = ssrFirstIndex($log, fn (string $sql) => str_contains($sql, 'from "schedules"') && str_contains($sql, '"series_id" = ?') && str_contains($sql, '"date_time" >= ?'));

        expect($lock)->not->toBeNull()->and($read)->not->toBeNull()->and($lock)->toBeLessThan($read);
    });
});
