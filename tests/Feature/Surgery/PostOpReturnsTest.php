<?php

declare(strict_types=1);

use App\Enums\{ScheduleAttendanceType, ScheduleSituation};
use App\Enums\Surgery\{PostOpReturnStatus, SurgicalCaseStatus};
use App\Events\Surgery\SurgeryPerformed;
use App\Models\{DoctorWorkSchedule, Schedule, ScheduleBlock};
use App\Models\Surgery\{SurgicalCase, SurgicalPostopReturn};
use App\Services\ScheduleSeriesService;
use App\Services\Surgery\PostOp\PostOpReturnService;
use App\Services\Surgery\Sections\PostOpSection;
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;

/*
 * Módulo cirúrgico (Fase 4) / M5 — retornos D1/D7/D30 na Agenda: série de
 * agendamentos PostOpEvaluation ligados ao caso, primeiro horário livre do
 * cirurgião a partir do dia-alvo, idempotência (reentrega do evento) e
 * isolamento de falhas entre os marcos.
 *
 * Relógio fixo: quinta-feira, 15/10/2026, 10:00 (a cirurgia).
 */

function spoPerformedCase(object $w, array $over = []): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id'        => (string) $w->patient->id,
        'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'             => 'Facoemulsificação com LIO',
        'eye'               => 'od',
        'procedures'        => [['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019', 'quantity' => 1]],
    ], $over));

    $case->forceFill([
        'status'       => SurgicalCaseStatus::Performed,
        'performed_at' => now(),
    ])->save();

    return $case->refresh();
}

/** Segunda a sexta, 08:00–10:00, de 30 em 30 minutos. */
function spoWorkWeek(object $w, string $from = '08:00:00', string $to = '10:00:00', array $days = [1, 2, 3, 4, 5]): void
{
    foreach ($days as $day) {
        DoctorWorkSchedule::query()->create(['doctor_id' => $w->doctor->id, 'day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
    }

    $w->doctor->update(['schedule_interval' => 30]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();
    spoWorkWeek($this->w);
    $this->service = app(PostOpReturnService::class);
});

it('agenda D1/D7/D30 como série de retornos do cirurgião, no primeiro horário livre', function () {
    $case = spoPerformedCase($this->w);

    $returns = $this->service->plan((string) $case->id);

    expect($returns)->toHaveCount(3);

    $schedules = Schedule::query()->where('surgical_case_id', $case->id)->orderBy('date_time')->get();

    expect($schedules)->toHaveCount(3)
        ->and($schedules->pluck('date_time')->map->format('Y-m-d H:i')->all())
        // D1 sexta 16/10; D7 quinta 22/10; D30 = sábado 14/11 → segunda 16/11 (sem expediente no fim de semana).
        ->toBe(['2026-10-16 08:00', '2026-10-22 08:00', '2026-11-16 08:00'])
        ->and($schedules->every(fn (Schedule $s) => (string) $s->doctor_id === (string) $this->w->doctor->id))->toBeTrue()
        ->and($schedules->every(fn (Schedule $s) => (string) $s->patient_id === (string) $this->w->patient->id))->toBeTrue()
        ->and($schedules->every(fn (Schedule $s) => $s->attendance_type === ScheduleAttendanceType::PostOpEvaluation))->toBeTrue()
        ->and($schedules->every(fn (Schedule $s) => $s->situation === ScheduleSituation::Scheduled && ! $s->is_fit_in))->toBeTrue()
        ->and($schedules->pluck('series_id')->unique())->toHaveCount(1)
        ->and($schedules->pluck('session_number')->all())->toBe([1, 2, 3])
        ->and($schedules->pluck('session_total')->unique()->all())->toBe([3])
        ->and($schedules->every(fn (Schedule $s) => $s->resources()->count() === 0))->toBeTrue();

    $rows = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->get()->keyBy(fn ($r) => $r->milestone->value);

    expect($rows['d30']->target_date->toDateString())->toBe('2026-11-14')
        ->and($rows['d30']->scheduled_for->toDateString())->toBe('2026-11-16')
        ->and($rows['d1']->status)->toBe(PostOpReturnStatus::Scheduled)
        ->and((string) $rows['d1']->schedule_id)->toBe((string) $schedules[0]->id);
});

it('é idempotente: rodar de novo ou reentregar o evento não duplica retorno', function () {
    $case = spoPerformedCase($this->w);

    $this->service->plan((string) $case->id);
    $this->service->plan((string) $case->id);
    SurgeryPerformed::dispatch((string) $case->id);

    expect(Schedule::query()->where('surgical_case_id', $case->id)->count())->toBe(3)
        ->and(SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->count())->toBe(3);
});

it('o evento "cirurgia realizada" cria os retornos pelo ouvinte', function () {
    $case = spoPerformedCase($this->w);

    SurgeryPerformed::dispatch((string) $case->id);

    expect(Schedule::query()->where('surgical_case_id', $case->id)->count())->toBe(3);
});

it('retorno que a recepção cancelou continua "já existiu": reentrega não recria', function () {
    $case = spoPerformedCase($this->w);
    $this->service->plan((string) $case->id);

    $d7 = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('milestone', 'd7')->firstOrFail();
    Schedule::query()->whereKey($d7->schedule_id)->update(['situation' => ScheduleSituation::Cancelled->value]);

    $this->service->plan((string) $case->id);

    expect(Schedule::query()->where('surgical_case_id', $case->id)->count())->toBe(3)
        ->and(Schedule::query()->where('surgical_case_id', $case->id)->where('situation', ScheduleSituation::Cancelled->value)->count())->toBe(1);
});

it('não move retorno já existente nem toma horário ocupado', function () {
    $case = spoPerformedCase($this->w);

    // 08:00 e 08:30 de sexta já estão ocupados por outros pacientes.
    foreach (['2026-10-16 08:00:00', '2026-10-16 08:30:00'] as $slot) {
        Schedule::query()->create([
            'entity_id' => $this->w->entity->id, 'doctor_id' => $this->w->doctor->id, 'full_name' => 'OUTRO',
            'date_time' => $slot, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
    }

    $this->service->plan((string) $case->id);

    $d1 = Schedule::query()->where('surgical_case_id', $case->id)->orderBy('date_time')->firstOrFail();

    expect($d1->date_time->format('Y-m-d H:i'))->toBe('2026-10-16 09:00');
});

it('dia-alvo sem expediente ou bloqueado avança para o próximo dia com horário e guarda a data-alvo', function () {
    $case = spoPerformedCase($this->w);

    // Feriado/ausência: o dia inteiro de sexta 16/10 bloqueado.
    ScheduleBlock::query()->create([
        'doctor_id' => $this->w->doctor->id, 'starts_at' => '2026-10-16 00:00:00', 'ends_at' => '2026-10-16 23:59:00', 'reason' => 'Feriado', 'type' => 'holiday',
    ]);

    $this->service->plan((string) $case->id);

    $d1 = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('milestone', 'd1')->firstOrFail();

    expect($d1->target_date->toDateString())->toBe('2026-10-16')
        ->and($d1->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-19 08:00'); // segunda-feira
});

it('não marca retorno em horário em que o cirurgião está operando (cirurgia agendada)', function () {
    $case = spoPerformedCase($this->w);

    // Outra cirurgia do mesmo cirurgião na sexta 16/10, 08:00–09:00.
    $other = spoPerformedCase($this->w, ['title' => 'Outra cirurgia']);
    $other->forceFill(['status' => SurgicalCaseStatus::Scheduled, 'scheduled_start' => '2026-10-16 08:00:00', 'scheduled_end' => '2026-10-16 09:00:00'])->save();

    $this->service->plan((string) $case->id);

    $d1 = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('milestone', 'd1')->firstOrFail();

    expect($d1->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-16 09:00');
});

it('sem nenhum horário no período: falha só desse retorno, com o motivo; os outros são agendados', function () {
    config(['surgery_postop.search_days' => 3]);
    $case = spoPerformedCase($this->w);

    // Os 3 dias de busca do D7 (22/10 a 24/10) ficam indisponíveis (23/10 sexta, 24/10 sábado sem expediente).
    ScheduleBlock::query()->create([
        'doctor_id' => $this->w->doctor->id, 'starts_at' => '2026-10-22 00:00:00', 'ends_at' => '2026-10-23 23:59:00', 'reason' => 'Congresso', 'type' => 'absence',
    ]);

    $returns = collect($this->service->plan((string) $case->id))->keyBy(fn ($r) => $r->milestone->value);

    expect($returns['d1']->status)->toBe(PostOpReturnStatus::Scheduled)
        ->and($returns['d7']->status)->toBe(PostOpReturnStatus::Failed)
        ->and($returns['d7']->failure_code)->toBe('no_slot')
        ->and($returns['d7']->schedule_id)->toBeNull()
        ->and($returns['d30']->status)->toBe(PostOpReturnStatus::Scheduled);

    // Corrige a escala (remove o bloqueio) e tenta de novo: só o que faltava é agendado.
    ScheduleBlock::query()->delete();

    $retried = collect($this->service->retry($case->refresh()))->keyBy(fn ($r) => $r->milestone->value);

    expect($retried['d7']->status)->toBe(PostOpReturnStatus::Scheduled)
        ->and(Schedule::query()->where('surgical_case_id', $case->id)->count())->toBe(3);
});

it('cirurgião inativo: falha com o código certo e nada é agendado', function () {
    $case = spoPerformedCase($this->w);
    $this->w->doctor->update(['active' => false]);

    $returns = $this->service->plan((string) $case->id);

    expect(collect($returns)->every(fn ($r) => $r->status === PostOpReturnStatus::Failed && $r->failure_code === 'surgeon_inactive'))->toBeTrue()
        ->and(Schedule::query()->where('surgical_case_id', $case->id)->count())->toBe(0);
});

it('só cirurgia REALIZADA gera retorno (alta dada, rascunho ou cancelada não)', function () {
    $draft = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Rascunho', 'eye' => 'od',
        'procedures' => [['description' => 'Procedimento']],
    ]);

    expect($this->service->plan((string) $draft->id))->toBe([]);

    $discharged = spoPerformedCase($this->w);
    $discharged->forceFill(['status' => SurgicalCaseStatus::Discharged])->save();

    expect($this->service->plan((string) $discharged->id))->toBe([])
        ->and(Schedule::query()->whereNotNull('surgical_case_id')->count())->toBe(0);
});

it('cancelOpen cancela só os retornos futuros ainda abertos e marca o plano', function () {
    $case = spoPerformedCase($this->w);
    $this->service->plan((string) $case->id);

    // O D1 já foi atendido: não é mexido.
    $d1 = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('milestone', 'd1')->firstOrFail();
    Schedule::query()->whereKey($d1->schedule_id)->update(['situation' => ScheduleSituation::Attended->value]);

    $count = $this->service->cancelOpen($case, 'Alta dada');

    expect($count)->toBe(2)
        ->and(Schedule::query()->where('surgical_case_id', $case->id)->where('situation', ScheduleSituation::Cancelled->value)->count())->toBe(2)
        ->and(Schedule::query()->whereKey($d1->schedule_id)->value('situation'))->toBe(ScheduleSituation::Attended)
        ->and(SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('status', 'cancelled')->count())->toBe(2);

    // Cancelados continuam cancelados mesmo se o evento for reentregue.
    $this->service->plan((string) $case->id);
    expect(Schedule::query()->where('surgical_case_id', $case->id)->count())->toBe(3);
});

it('isola a clínica: o retorno herda a clínica do caso e o plano nunca cruza clínicas', function () {
    $other     = surgeryWorld();
    $caseOther = spoPerformedCase($other);
    $case      = spoPerformedCase($this->w);

    $this->service->plan((string) $case->id);

    expect(Schedule::query()->withoutGlobalScopes()->where('surgical_case_id', $case->id)->pluck('entity_id')->unique()->all())->toBe([(string) $this->w->entity->id])
        ->and(Schedule::query()->withoutGlobalScopes()->where('surgical_case_id', $caseOther->id)->count())->toBe(0);
});

// ── Frequência por deslocamentos na série da Agenda ─────────────────────────

it('a série da Agenda gera datas por deslocamento: âncora + N dias, ordenadas e sem repetir', function () {
    $series = app(ScheduleSeriesService::class);

    $dates = $series->datesFromOffsets(Carbon::parse('2026-10-15 09:00:00'), [30, 1, 7, 7, -2, '1']);

    expect(array_map(fn (Carbon $d) => $d->format('Y-m-d H:i'), $dates))
        ->toBe(['2026-10-16 09:00', '2026-10-22 09:00', '2026-11-14 09:00']);
});

it('a prévia de série aceita a frequência "offsets" e devolve cada data com seus conflitos', function () {
    $response = surgeryAs($this->w, 'admin')->getJson(route('panel.schedules.series.preview', [
        'doctor_id' => (string) $this->w->doctor->id,
        'date_time' => '2026-10-16 08:00',
        'frequency' => 'offsets',
        'offsets'   => [6, 29],
    ]));

    $response->assertOk()
        ->assertJsonCount(3, 'sessions')
        ->assertJsonPath('sessions.0.date_time', '2026-10-16T08:00')
        ->assertJsonPath('sessions.1.date_time', '2026-10-22T08:00')
        ->assertJsonPath('sessions.2.date_time', '2026-11-14T08:00');

    // 14/11 é sábado: sem expediente.
    expect($response->json('sessions.2.errors'))->not->toBeEmpty()
        ->and($response->json('sessions.0.errors'))->toBe([]);
});

it('a prévia exige os deslocamentos quando a frequência é "offsets"', function () {
    surgeryAs($this->w, 'admin')->getJson(route('panel.schedules.series.preview', [
        'doctor_id' => (string) $this->w->doctor->id,
        'date_time' => '2026-10-16 08:00',
        'frequency' => 'offsets',
    ]))->assertStatus(422)->assertJsonValidationErrors('offsets');
});

it('remarcar o retorno pela Agenda leva o caso junto: o retorno aponta para o agendamento novo (e o horário novo) e a alta cancela o novo', function () {
    $case = spoPerformedCase($this->w);
    $this->service->plan((string) $case->id);

    $d7  = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('milestone', 'd7')->firstOrFail();
    $old = Schedule::query()->findOrFail($d7->schedule_id); // quinta 22/10 08:00

    $res = surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.schedules.reschedule', $old), ['date_time' => '2026-10-26 09:00'])
        ->assertStatus(201);

    $new = Schedule::query()->findOrFail($res->json('new_schedule.id'));
    $d7->refresh();

    expect((string) $new->surgical_case_id)->toBe((string) $case->id)
        ->and($new->attendance_type)->toBe(ScheduleAttendanceType::PostOpEvaluation)
        ->and($new->date_time->format('Y-m-d H:i'))->toBe('2026-10-26 09:00')
        ->and($old->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($d7->status)->toBe(PostOpReturnStatus::Scheduled)
        ->and((string) $d7->schedule_id)->toBe((string) $new->id)
        ->and($d7->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-26 09:00');

    // A aba do caso mostra o agendamento NOVO (não o cancelado) e o desvio do dia-alvo.
    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))->assertInertia(function ($page) use ($new) {
        $page->where('sections.postop.returns.1.milestone.value', 'd7')
            ->where('sections.postop.returns.1.schedule.id', (string) $new->id)
            ->where('sections.postop.returns.1.deviated', true);
    });

    // Alta: cancela o agendamento vigente do retorno (o novo), não o cancelado de antes.
    $this->service->cancelOpen($case->refresh(), 'Alta');

    expect($new->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($d7->refresh()->status)->toBe(PostOpReturnStatus::Cancelled);
});

it('a aba aponta o desvio do dia-alvo pelo horário VIVO do agendamento (a recepção pode mover o retorno depois)', function () {
    $case = spoPerformedCase($this->w);
    $this->service->plan((string) $case->id);

    $d1 = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('milestone', 'd1')->firstOrFail();

    $page = fn (bool $deviated) => surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($p) => $p->where('sections.postop.returns.0.milestone.value', 'd1')->where('sections.postop.returns.0.deviated', $deviated));

    // Agendado no próprio dia-alvo (sexta 16/10): sem desvio.
    expect($d1->target_date->toDateString())->toBe('2026-10-16');
    $page(false);

    // Movido no lugar para a segunda seguinte: o horário gravado no retorno ficou velho, a aba não.
    Schedule::query()->whereKey($d1->schedule_id)->update(['date_time' => '2026-10-19 08:00:00']);

    expect($d1->refresh()->scheduled_for->toDateString())->toBe('2026-10-16');
    $page(true);
});

it('remarcar um agendamento comum (sem caso) segue como antes: sem retorno para acompanhar', function () {
    $plain = Schedule::query()->create([
        'entity_id' => $this->w->entity->id, 'doctor_id' => $this->w->doctor->id, 'full_name' => 'OUTRO',
        'date_time' => '2026-10-20 08:00:00', 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);

    $res = surgeryAs($this->w, 'secretary')->postJson(route('panel.schedules.reschedule', $plain), ['date_time' => '2026-10-21 09:00'])->assertStatus(201);

    expect($res->json('new_schedule.surgical_case_id'))->toBeNull();
});

it('a alta que corre junto com a remarcação cancela o agendamento VIGENTE do retorno, não o que acabou de ser cancelado', function () {
    $case = spoPerformedCase($this->w);
    $this->service->plan((string) $case->id);

    $d7    = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->where('milestone', 'd7')->firstOrFail();
    $stale = SurgicalPostopReturn::query()->findOrFail($d7->id); // a alta leu o retorno ANTES da remarcação
    $old   = Schedule::query()->findOrFail($d7->schedule_id);

    $new = Schedule::query()->findOrFail(
        surgeryAs($this->w, 'secretary')->postJson(route('panel.schedules.reschedule', $old), ['date_time' => '2026-10-26 09:00'])->assertStatus(201)->json('new_schedule.id'),
    );

    $cancelOne = new ReflectionMethod($this->service, 'cancelOne');

    expect($cancelOne->invoke($this->service, $stale, 'Alta'))->toBeTrue()
        ->and($new->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($d7->refresh()->status)->toBe(PostOpReturnStatus::Cancelled);
});

it('o retorno cirúrgico na Agenda traz o atalho para a avaliação pós-op só para o médico', function () {
    $case = spoPerformedCase($this->w);
    $this->service->plan((string) $case->id);
    $schedule = Schedule::query()->where('surgical_case_id', $case->id)->orderBy('date_time')->firstOrFail();

    $doctor    = surgeryAs($this->w, 'doctor')->getJson(route('panel.schedules.show', $schedule))->assertOk();
    $secretary = surgeryAs($this->w, 'secretary')->getJson(route('panel.schedules.show', $schedule))->assertOk();

    expect($doctor->json('postop_case_url') ?? $doctor->json('data.postop_case_url'))->toBe(route('panel.surgery.show', $case) . '#tab=' . PostOpSection::KEY)
        ->and($secretary->json('postop_case_url') ?? $secretary->json('data.postop_case_url'))->toBeNull();

    // Consulta comum (sem caso) não ganha o atalho.
    $plain = Schedule::query()->create([
        'entity_id' => $this->w->entity->id, 'doctor_id' => $this->w->doctor->id, 'full_name' => 'OUTRO',
        'date_time' => '2026-10-20 08:00:00', 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);

    $res = surgeryAs($this->w, 'doctor')->getJson(route('panel.schedules.show', $plain))->assertOk();
    expect($res->json('postop_case_url') ?? $res->json('data.postop_case_url'))->toBeNull();
});

it('reagendar a série a partir do D7 leva os retornos D7 e D30 para os agendamentos novos; o D1 (antes da escolhida) fica', function () {
    $case = spoPerformedCase($this->w);
    $this->service->plan((string) $case->id);   // D1 sex 16/10 08:00, D7 qui 22/10 08:00, D30 seg 16/11 08:00

    $returns = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->get()->keyBy(fn ($r) => $r->milestone->value);
    $oldD1   = Schedule::query()->findOrFail($returns['d1']->schedule_id);
    $oldD7   = Schedule::query()->findOrFail($returns['d7']->schedule_id);
    $oldD30  = Schedule::query()->findOrFail($returns['d30']->schedule_id);

    // +1 dia e 30 min: D7 vai para sexta 23/10 08:30 e o D30 para terça 17/11 08:30.
    $res = surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.schedules.series.reschedule', $oldD7), ['date_time' => '2026-10-23 08:30'])
        ->assertCreated()
        ->assertJsonPath('updated', 2);

    $newOf  = fn (Schedule $old) => Schedule::query()->findOrFail(collect($res->json('new_schedules'))->firstWhere('old_id', (string) $old->id)['id']);
    $newD7  = $newOf($oldD7);
    $newD30 = $newOf($oldD30);

    $returns = SurgicalPostopReturn::query()->where('surgical_case_id', $case->id)->get()->keyBy(fn ($r) => $r->milestone->value);

    expect((string) $returns['d7']->schedule_id)->toBe((string) $newD7->id)
        ->and($returns['d7']->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-23 08:30')
        ->and((string) $returns['d30']->schedule_id)->toBe((string) $newD30->id)
        ->and($returns['d30']->scheduled_for->format('Y-m-d H:i'))->toBe('2026-11-17 08:30')
        ->and([$returns['d7']->status, $returns['d30']->status])->toBe([PostOpReturnStatus::Scheduled, PostOpReturnStatus::Scheduled])
        // O D1 está ANTES da âncora: o retorno e o agendamento continuam onde estavam.
        ->and((string) $returns['d1']->schedule_id)->toBe((string) $oldD1->id)
        ->and($oldD1->refresh()->situation)->toBe(ScheduleSituation::Scheduled)
        ->and($oldD1->date_time->format('Y-m-d H:i'))->toBe('2026-10-16 08:00')
        // Os agendamentos novos continuam sendo do caso, na mesma série, com a mesma numeração.
        ->and([(string) $newD7->surgical_case_id, $newD7->attendance_type, $newD7->session_number, $newD7->session_total])->toBe([(string) $case->id, ScheduleAttendanceType::PostOpEvaluation, 2, 3])
        ->and([(string) $newD30->surgical_case_id, $newD30->session_number, (string) $newD30->series_id])->toBe([(string) $case->id, 3, (string) $oldD1->series_id])
        ->and($oldD7->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($oldD30->refresh()->situation)->toBe(ScheduleSituation::Cancelled);

    // A alta cancela os agendamentos VIGENTES dos retornos (os novos).
    $this->service->cancelOpen($case->refresh(), 'Alta');

    expect($newD7->refresh()->situation)->toBe(ScheduleSituation::Cancelled)
        ->and($newD30->refresh()->situation)->toBe(ScheduleSituation::Cancelled);
});
