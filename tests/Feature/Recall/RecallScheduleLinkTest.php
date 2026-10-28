<?php

declare(strict_types=1);

/**
 * "Agendar" a partir da tela de Retornos (Fase 6): o formulário da Agenda abre com o paciente e o retorno
 * escolhidos (`?recall=<id>`), e o agendamento criado vira o agendamento DAQUELE retorno (`recall_id`) — na mesma
 * transação, sem esperar a reavaliação. Vínculo recusado não derruba o agendamento.
 */

use App\Domains\Recall\Enums\RecallLifecycle as Life;
use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent};
use App\Enums\ClientRule;
use App\Models\{Covenant, DoctorWorkSchedule, EntityUser, Patient, People, Schedule, User};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Notification};
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $this->user      = User::factory()->create();
    $this->secretary = createEntityUser($this->entity, $this->user, ClientRule::Secretary->value);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'MARIA DOS RETORNOS', 'cellphone' => '11977776666'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    rcfRecord($this->entity, $this->patient, $this->doctor, now()->subDays(10)->toDateString(), 90);
    rcfEvaluate($this->entity, $this->patient, now()->toDateString());
    $this->recall = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    $this->monday = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
});

function rslAs(object $test, ?EntityUser $eu = null): object
{
    $eu ??= $test->secretary;

    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function rslPrefill(object $test, string $recallId, ?EntityUser $eu = null): ?array
{
    $prefill = 'unset';

    rslAs($test, $eu)->get(route('panel.schedules.index', ['recall' => $recallId, 'new' => 1]))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$prefill) {
            $prefill = $page->toArray()['props']['recallPrefill'] ?? null;
        });

    return $prefill;
}

function rslPayload(object $test, array $extra = []): array
{
    return array_merge([
        'doctor_id'  => $test->doctor->id,
        'patient_id' => $test->patient->id,
        'full_name'  => 'MARIA DOS RETORNOS',
        'date_time'  => $test->monday->format('Y-m-d H:i'),
    ], $extra);
}

it('a Agenda abre o formulário com o paciente e o retorno (rótulo "o que falta", nunca a condição)', function () {
    $prefill = rslPrefill($this, $this->recall->id);

    expect($prefill)->toMatchArray([
        'recall_id'    => (string) $this->recall->id,
        'recall_label' => 'Retorno pedido pelo médico',
        'patient_id'   => (string) $this->patient->id,
        'full_name'    => 'MARIA DOS RETORNOS',
        'cellphone'    => '11977776666',
    ])->and(array_keys($prefill))->not->toContain('condition');
});

it('sem prefill quando o retorno não existe, é de outra clínica, está dispensado, suspenso ou o plano não tem a feature', function () {
    expect(rslPrefill($this, '01a00000-0000-7000-8000-000000000000'))->toBeNull()
        ->and(rslPrefill($this, 'nao-e-uuid'))->toBeNull();

    $other        = rcfClinic(recall: true);
    $otherPatient = rcfPatient($other['entity']);
    rcfRecord($other['entity'], $otherPatient, $other['doctor'], now()->subDays(10)->toDateString(), 90);
    rcfEvaluate($other['entity'], $otherPatient, now()->toDateString());
    $foreign = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $otherPatient->id)->sole();

    expect(rslPrefill($this, $foreign->id))->toBeNull();

    $this->recall->forceFill(['lifecycle' => Life::Dismissed->value])->save();
    expect(rslPrefill($this, $this->recall->id))->toBeNull();

    $this->recall->forceFill(['lifecycle' => Life::Open->value, 'rule_suspended' => true])->save();
    expect(rslPrefill($this, $this->recall->id))->toBeNull();

    // Plano sem a feature: o parâmetro é ignorado (não expõe dado de retorno).
    ['entity' => $noPlan] = rcfClinic();
    rcfGrantRecall($noPlan, enabled: false);
    $noPlanUser = User::factory()->create();
    $noPlanEu   = createEntityUser($noPlan, $noPlanUser, ClientRule::Admin->value);
    $this->recall->forceFill(['rule_suspended' => false])->save();

    expect(rslPrefill($this, $this->recall->id, $noPlanEu))->toBeNull();
});

it('financeiro não recebe o prefill (não opera o recall)', function () {
    $financial = createEntityUser($this->entity, User::factory()->create(), ClientRule::Financial->value);

    rslAs($this, $financial)->get(route('panel.schedules.index', ['recall' => $this->recall->id]))->assertForbidden();
});

it('criar o agendamento com recall_id vincula o retorno NA MESMA transação e grava o evento com quem agendou', function () {
    $response = rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this, ['recall_id' => (string) $this->recall->id]));

    $response->assertCreated();

    $schedule = Schedule::query()->findOrFail($response->json('data.id'));
    $event    = PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $this->recall->id)->where('event', 'scheduled')->sole();

    expect($this->recall->fresh()->scheduled_schedule_id)->toBe((string) $schedule->id)
        ->and($event->actor_user_id)->toBe((string) $this->user->id)
        ->and($event->meta['schedule_id'])->toBe((string) $schedule->id);
});

it('agendando um retorno, a trava do paciente no recall vem ANTES do INSERT do agendamento (e da outbox que ele dispara)', function () {
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $sql = $query->sql;

        if (str_contains($sql, 'pg_advisory_xact_lock') && ($query->bindings[0] ?? null) === 'easyeye.recall.patient') {
            $statements[] = 'recall-patient-lock';
        } elseif (str_starts_with($sql, 'insert into "schedules"')) {
            $statements[] = 'schedule-insert';
        } elseif (str_contains($sql, 'insert into recall_dirty_patients') || str_contains($sql, 'INSERT INTO recall_dirty_patients')) {
            $statements[] = 'outbox';
        }
    });

    rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this, ['recall_id' => (string) $this->recall->id]))->assertCreated();

    // A trava aparece de novo no vínculo (re-entrante na sessão), mas a PRIMEIRA vem antes de tudo.
    expect($statements)->toContain('schedule-insert')->toContain('outbox')
        ->and(array_search('recall-patient-lock', $statements, true))->toBeLessThan(array_search('schedule-insert', $statements, true))
        ->and(array_search('recall-patient-lock', $statements, true))->toBeLessThan(array_search('outbox', $statements, true));
});

it('agendar SEM retorno não toma a trava do recall (agendar comum não paga a espera)', function () {
    $locked = false;

    DB::listen(function ($query) use (&$locked): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock') && ($query->bindings[0] ?? null) === 'easyeye.recall.patient') {
            $locked = true;
        }
    });

    rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this))->assertCreated();

    expect($locked)->toBeFalse();
});

it('o vínculo escolhido sobrevive à reavaliação, mesmo havendo outro agendamento aberto mais próximo', function () {
    ['schedule' => $earlier] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => $this->monday->copy()->subDay()->setTime(10, 0)]);

    $response = rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this, ['recall_id' => (string) $this->recall->id]));
    $chosen   = (string) $response->json('data.id');

    rcfEvaluate($this->entity, $this->patient, now()->toDateString());

    expect($earlier->date_time->lt($this->monday))->toBeTrue()
        ->and($this->recall->fresh()->scheduled_schedule_id)->toBe($chosen);
});

it('retorno de OUTRO paciente (ou de outra clínica): o agendamento não é criado e o erro aponta recall_id', function () {
    $someoneElse = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'OUTRO PACIENTE'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this, ['patient_id' => $someoneElse->id, 'full_name' => 'OUTRO PACIENTE', 'recall_id' => (string) $this->recall->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recall_id');

    $other        = rcfClinic(recall: true);
    $otherPatient = rcfPatient($other['entity']);
    rcfRecord($other['entity'], $otherPatient, $other['doctor'], now()->subDays(10)->toDateString(), 90);
    rcfEvaluate($other['entity'], $otherPatient, now()->toDateString());
    $foreign = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $otherPatient->id)->sole();

    rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this, ['recall_id' => (string) $foreign->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recall_id');

    expect(Schedule::query()->where('patient_id', $someoneElse->id)->count())->toBe(0)
        ->and($this->recall->fresh()->scheduled_schedule_id)->toBeNull();
});

it('o retorno foi tratado por outra pessoa antes do envio: o agendamento É criado, só o vínculo fica para a reavaliação', function () {
    $this->recall->forceFill(['lifecycle' => Life::Dismissed->value, 'dismissed_reason' => 'Já foi tratado'])->save();

    $response = rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this, ['recall_id' => (string) $this->recall->id]));

    $response->assertCreated();

    expect(Schedule::query()->findOrFail($response->json('data.id')))->not->toBeNull()
        ->and($this->recall->fresh()->scheduled_schedule_id)->toBeNull()
        ->and($this->recall->fresh()->lifecycle)->toBe(Life::Dismissed);
});

it('plano sem a feature: recall_id é ignorado sem erro', function () {
    ['entity' => $noPlan, 'doctor' => $doctor] = rcfClinic();
    rcfGrantRecall($noPlan, enabled: false);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15]);

    $eu      = createEntityUser($noPlan, User::factory()->create(), ClientRule::Secretary->value);
    $patient = rcfPatient($noPlan);
    rcfRecord($noPlan, $patient, $doctor, now()->subDays(10)->toDateString(), 90);
    rcfEvaluate($noPlan, $patient, now()->toDateString());
    $recall = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->sole();

    rslAs($this, $eu)->postJson(route('panel.schedules.store'), [
        'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'full_name' => 'SEM PLANO', 'date_time' => $this->monday->format('Y-m-d H:i'), 'recall_id' => (string) $recall->id,
    ])->assertCreated();

    expect($recall->fresh()->scheduled_schedule_id)->toBeNull();
});

it('editar um agendamento não aceita recall_id (o vínculo só nasce no cadastro)', function () {
    $created  = rslAs($this)->postJson(route('panel.schedules.store'), rslPayload($this))->assertCreated();
    $schedule = Schedule::query()->findOrFail($created->json('data.id'));

    rslAs($this)->putJson(route('panel.schedules.update', $schedule), rslPayload($this, ['recall_id' => (string) $this->recall->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recall_id');
});
