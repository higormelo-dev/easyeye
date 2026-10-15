<?php

declare(strict_types=1);

/**
 * As regras de negócio das ações da equipe e do vínculo com o agendamento, direto no serviço (a tela e os
 * FormRequests já barram boa parte; aqui é a segunda trava: quem chamar o serviço por outro caminho — comando,
 * job, outra tela — esbarra nas mesmas regras). Relógio fixo: 01/02/2026.
 */

use App\Domains\Recall\Enums\{RecallContactOutcome, RecallLifecycle as Life};
use App\Domains\Recall\Exceptions\RecallActionException as Refused;
use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent};
use App\Domains\Recall\Services\{RecallActionService, RecallLinkService};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    $this->patient                                         = rcfPatient($this->entity);
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-05', 45);
    rcfEvaluate($this->entity, $this->patient);

    $this->recall  = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    $this->actions = app(RecallActionService::class);
    $this->today   = CarbonImmutable::parse('2026-02-01');
});

function raxRefused(callable $call): ?Refused
{
    try {
        $call();
    } catch (Refused $e) {
        return $e;
    }

    return null;
}

it('adiar: só de amanhã até um ano; hoje, passado e além do limite são recusados', function () {
    foreach (['2026-02-01', '2026-01-15', '2027-02-02'] as $until) {
        $refused = raxRefused(fn () => $this->actions->snooze((string) $this->entity->id, (string) $this->recall->id, CarbonImmutable::parse($until), null, null, $this->today));

        expect($refused?->reason())->toBe(Refused::INVALID_DATE, $until);
    }

    foreach (['2026-02-02', '2027-02-01'] as $until) {
        $row = $this->actions->snooze((string) $this->entity->id, (string) $this->recall->id, CarbonImmutable::parse($until), null, null, $this->today);

        expect($row->lifecycle)->toBe(Life::Snoozed)->and($row->snoozed_until->toDateString())->toBe($until);
        $this->actions->reopen((string) $this->entity->id, (string) $this->recall->id);
    }
});

it('dispensar: o motivo é obrigatório (vazio, só espaços ou curto demais), com o mínimo certo aceito', function () {
    foreach (['', '   ', 'ab', " a \n "] as $reason) {
        $refused = raxRefused(fn () => $this->actions->dismiss((string) $this->entity->id, (string) $this->recall->id, $reason));

        expect($refused?->reason())->toBe(Refused::REASON_REQUIRED, json_encode($reason));
    }

    $row = $this->actions->dismiss((string) $this->entity->id, (string) $this->recall->id, "  Não   quer  \n voltar  ");

    expect($row->lifecycle)->toBe(Life::Dismissed)->and($row->dismissed_reason)->toBe('Não quer voltar');
});

it('reabrir: só retorno adiado ou dispensado', function () {
    $refused = raxRefused(fn () => $this->actions->reopen((string) $this->entity->id, (string) $this->recall->id));

    expect($refused?->reason())->toBe(Refused::NOT_REOPENABLE)
        ->and($this->recall->fresh()->lifecycle)->toBe(Life::Open);

    $this->recall->forceFill(['lifecycle' => Life::Closed->value, 'closed_reason' => 'completed'])->save();

    expect(raxRefused(fn () => $this->actions->reopen((string) $this->entity->id, (string) $this->recall->id))?->reason())->toBe(Refused::NOT_REOPENABLE);
});

it('"feito fora": data futura e anterior ao início do acompanhamento são recusadas; a de hoje vale', function () {
    expect(raxRefused(fn () => $this->actions->doneElsewhere((string) $this->entity->id, (string) $this->recall->id, CarbonImmutable::parse('2026-02-02'), null, $this->today))?->reason())->toBe(Refused::INVALID_DATE)
        ->and(raxRefused(fn () => $this->actions->doneElsewhere((string) $this->entity->id, (string) $this->recall->id, CarbonImmutable::parse('2026-01-04'), null, $this->today))?->reason())->toBe(Refused::INVALID_DATE);

    $row = $this->actions->doneElsewhere((string) $this->entity->id, (string) $this->recall->id, CarbonImmutable::parse('2026-02-01'), null, $this->today);

    expect($row->lifecycle)->toBe(Life::Closed)->and($row->closed_reason)->toBe('done_elsewhere');
});

it('paciente removido (a linha ainda existe): nenhuma ação, como se não existisse', function () {
    $this->patient->delete();

    foreach ([
        fn () => $this->actions->contact((string) $this->entity->id, (string) $this->recall->id, RecallContactOutcome::Reached),
        fn () => $this->actions->dismiss((string) $this->entity->id, (string) $this->recall->id, 'Qualquer motivo'),
        fn () => $this->actions->reopen((string) $this->entity->id, (string) $this->recall->id),
    ] as $call) {
        expect(raxRefused($call)?->reason())->toBe(Refused::NOT_FOUND);
    }

    expect($this->recall->fresh()->contact_count)->toBe(0);
});

it('ação em retorno de outra clínica é "não encontrado" — mesmo com o id certo', function () {
    $other = rcfClinic(recall: true);

    expect(raxRefused(fn () => $this->actions->contact((string) $other['entity']->id, (string) $this->recall->id, RecallContactOutcome::Reached))?->reason())->toBe(Refused::NOT_FOUND)
        ->and($this->recall->fresh()->contact_count)->toBe(0);
});

it('o evento de cada ação guarda quem fez (ou "system") e o que foi decidido', function () {
    $this->actions->contact((string) $this->entity->id, (string) $this->recall->id, RecallContactOutcome::WrongNumber, '  Número  errado ');
    $this->actions->snooze((string) $this->entity->id, (string) $this->recall->id, CarbonImmutable::parse('2026-03-01'), 'Viagem', null, $this->today);

    $events = PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $this->recall->id)->whereIn('event', ['contacted', 'snoozed'])->orderBy('created_at')->get()->keyBy('event');

    expect($events['contacted']->actor_label)->toBe('system')
        ->and($events['contacted']->actor_user_id)->toBeNull()
        ->and($events['contacted']->meta)->toBe(['outcome' => 'wrong_number', 'note' => 'Número errado'])
        ->and($events['snoozed']->meta)->toBe(['until' => '2026-03-01', 'reason' => 'Viagem']);
});

// ── o vínculo com o agendamento ─────────────────────────────────────────────

it('vínculo: agendamento de outro paciente ou de outra clínica é recusado', function () {
    $link  = app(RecallLinkService::class);
    $other = rcfPatient($this->entity);

    ['schedule' => $foreignPatient] = createScheduleForEntity($this->entity, ['patient_id' => $other->id, 'date_time' => '2026-03-01 09:00:00']);

    expect(raxRefused(fn () => $link->link((string) $this->entity->id, (string) $this->recall->id, $foreignPatient))?->reason())->toBe(Refused::LINK_MISMATCH)
        ->and($this->recall->fresh()->scheduled_schedule_id)->toBeNull();

    $otherClinic                   = rcfClinic(recall: true);
    $stranger                      = rcfPatient($otherClinic['entity']);
    ['schedule' => $foreignClinic] = createScheduleForEntity($otherClinic['entity'], ['patient_id' => $stranger->id, 'date_time' => '2026-03-01 09:00:00']);

    expect(raxRefused(fn () => $link->link((string) $this->entity->id, (string) $this->recall->id, $foreignClinic))?->reason())->toBe(Refused::LINK_MISMATCH);

    // O agendamento DO paciente, na própria clínica, vincula.
    ['schedule' => $mine] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-01 09:00:00']);

    expect($link->link((string) $this->entity->id, (string) $this->recall->id, $mine)->scheduled_schedule_id)->toBe((string) $mine->id);
});

it('vínculo: regra suspensa, retorno tratado e retorno de outra clínica são recusados', function () {
    $link                     = app(RecallLinkService::class);
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-01 09:00:00']);

    $this->recall->forceFill(['rule_suspended' => true])->save();
    expect(raxRefused(fn () => $link->link((string) $this->entity->id, (string) $this->recall->id, $schedule))?->reason())->toBe(Refused::RULE_SUSPENDED);

    $this->recall->forceFill(['rule_suspended' => false, 'lifecycle' => Life::Dismissed->value])->save();
    expect(raxRefused(fn () => $link->link((string) $this->entity->id, (string) $this->recall->id, $schedule))?->reason())->toBe(Refused::NOT_WORKABLE);

    $this->recall->forceFill(['lifecycle' => Life::Open->value])->save();
    $other = rcfClinic(recall: true);
    expect(raxRefused(fn () => $link->link((string) $other['entity']->id, (string) $this->recall->id, $schedule))?->reason())->toBe(Refused::NOT_FOUND);
});
