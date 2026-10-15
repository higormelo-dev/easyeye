<?php

declare(strict_types=1);

/**
 * Suspender/aposentar (ou retomar) uma versão do protocolo chega aos retornos que ela calculou NA HORA: os pacientes
 * entram na outbox na mesma transação da governança, e as ações da equipe e o agendamento conferem a versão VIVA em
 * vez de confiar só no flag derivado `rule_suspended` (que só muda quando o paciente é reavaliado).
 * Relógio fixo: 01/02/2026.
 */

use App\Domains\Recall\Enums\RecallContactOutcome;
use App\Domains\Recall\Exceptions\RecallActionException as Refused;
use App\Domains\Recall\Models\PatientRecall;
use App\Domains\Recall\Services\{ProtocolGovernanceService, RecallActionService, RecallLinkService};
use App\Enums\{ClientRule, Eye};
use App\Models\{Schedule, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->adoption = rcfAdopt($this->entity, 'glaucoma');
    $this->version  = $this->adoption->version->fresh();

    // Paciente COM retornos do protocolo (consulta e campimetria) e o pedido do médico.
    $this->patient = rcfPatient($this->entity);
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2025-01-15');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-12-01', 45);
    rcfEvaluate($this->entity, $this->patient);

    // Paciente SÓ com o pedido do médico: nenhuma versão de protocolo o calculou.
    $this->plain = rcfPatient($this->entity);
    rcfRecord($this->entity, $this->plain, $this->doctor, '2025-12-01', 45);
    rcfEvaluate($this->entity, $this->plain);

    $this->governance = app(ProtocolGovernanceService::class);
    $this->actions    = app(RecallActionService::class);
    $this->today      = CarbonImmutable::parse('2026-02-01');

    DB::table('recall_dirty_patients')->delete();
});

function pgpDirty(): array
{
    return DB::table('recall_dirty_patients')->orderBy('patient_id')->get()->map(fn ($row) => [(string) $row->patient_id, (string) $row->reason])->all();
}

function pgpRow(object $test, string $taskKey): PatientRecall
{
    return rcfRecalls($test->patient)->get($taskKey);
}

it('suspender a versão marca na outbox os pacientes dos retornos DELA (e só eles), com o motivo da governança', function () {
    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');

    expect(pgpDirty())->toBe([[(string) $this->patient->id, 'protocol_governance']]);
});

it('retomar a versão também marca: as linhas paradas precisam voltar logo', function () {
    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');
    rcfEvaluate($this->entity, $this->patient);
    DB::table('recall_dirty_patients')->delete();

    $this->governance->resume($this->version->fresh());

    expect(pgpDirty())->toBe([[(string) $this->patient->id, 'protocol_governance']]);
});

it('aposentar a versão marca; mudar entre estados em uso (substituída por uma nova) não marca ninguém', function () {
    $second = rcfReleaseSecond($this->version);

    // A 1ª virou "substituída" (segue em uso para quem a adotou): o retorno dela não mudou.
    expect($this->version->fresh()->state->value)->toBe('superseded')
        ->and($second->state->value)->toBe('released')
        ->and(pgpDirty())->toBe([]);

    $this->governance->retire($this->version->fresh(), 'A versão antiga tinha o intervalo errado.');

    expect(pgpDirty())->toBe([[(string) $this->patient->id, 'protocol_governance']]);
});

it('retorno encerrado não é marcado (só aberto ou adiado)', function () {
    PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('source', 'protocol')->update(['lifecycle' => 'closed', 'closed_reason' => 'completed']);

    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');

    expect(pgpDirty())->toBe([]);
});

it('com a versão suspensa e o paciente ainda NÃO reavaliado, contato, adiamento, dispensa e reabertura já são recusados', function () {
    $row = pgpRow($this, 'consulta');

    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');

    // O flag derivado ainda diz "em uso": é a janela entre a governança e a reavaliação.
    expect($row->fresh()->rule_suspended)->toBeFalse();

    foreach ([
        fn () => $this->actions->contact((string) $this->entity->id, (string) $row->id, RecallContactOutcome::Reached),
        fn () => $this->actions->snooze((string) $this->entity->id, (string) $row->id, $this->today->addDays(10), null, null, $this->today),
        fn () => $this->actions->dismiss((string) $this->entity->id, (string) $row->id, 'Não quer voltar'),
        fn () => $this->actions->reopen((string) $this->entity->id, (string) $row->id),
        fn () => $this->actions->doneElsewhere((string) $this->entity->id, (string) $row->id, CarbonImmutable::parse('2026-01-20'), null, $this->today),
    ] as $call) {
        $refused = null;

        try {
            $call();
        } catch (Refused $e) {
            $refused = $e;
        }

        expect($refused?->reason())->toBe(Refused::RULE_SUSPENDED);
    }

    expect($row->fresh()->contact_count)->toBe(0)->and($row->fresh()->lifecycle->value)->toBe('open');
});

it('o retorno pedido pelo médico (sem protocolo) segue recebendo ações com a versão suspensa', function () {
    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');

    $order = pgpRow($this, 'doctor_order');
    $done  = $this->actions->contact((string) $this->entity->id, (string) $order->id, RecallContactOutcome::Reached);

    expect($done->contact_count)->toBe(1);
});

it('depois de reavaliar e retomar a versão, as ações voltam', function () {
    $row = pgpRow($this, 'consulta');

    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');
    rcfEvaluate($this->entity, $this->patient);
    expect(pgpRow($this, 'consulta')->rule_suspended)->toBeTrue();

    $this->governance->resume($this->version->fresh());
    rcfEvaluate($this->entity, $this->patient);

    $done = $this->actions->contact((string) $this->entity->id, (string) $row->id, RecallContactOutcome::Reached);

    expect($done->contact_count)->toBe(1)->and($done->rule_suspended)->toBeFalse();
});

it('adoção desativada ou trocada por outro conteúdo não vale para a ação', function () {
    $row = pgpRow($this, 'consulta');

    $this->adoption->forceFill(['adopted_content_hash' => str_repeat('0', 64)])->save();

    $refused = null;

    try {
        $this->actions->contact((string) $this->entity->id, (string) $row->id, RecallContactOutcome::Reached);
    } catch (Refused $e) {
        $refused = $e;
    }

    expect($refused?->reason())->toBe(Refused::RULE_SUSPENDED);
});

it('vincular um agendamento ao retorno com a versão suspensa é recusado antes da reavaliação', function () {
    $row      = pgpRow($this, 'consulta');
    $schedule = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
        'full_name' => 'PACIENTE', 'date_time' => CarbonImmutable::parse('2026-02-10 09:00'), 'situation' => 1, 'active' => true,
    ]);

    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');

    $refused = null;

    try {
        app(RecallLinkService::class)->link((string) $this->entity->id, (string) $row->id, $schedule);
    } catch (Refused $e) {
        $refused = $e;
    }

    expect($refused?->reason())->toBe(Refused::RULE_SUSPENDED)->and($row->fresh()->scheduled_schedule_id)->toBeNull();
});

it('a Agenda não abre o prefill do retorno de uma versão suspensa, mesmo antes da reavaliação', function () {
    $row       = pgpRow($this, 'consulta');
    $secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $prefill   = fn () => data_get(
        $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->get(route('panel.schedules.index', ['recall' => $row->id, 'new' => 1]))->assertOk()->viewData('page')['props'] ?? [],
        'recallPrefill',
    );

    expect($prefill())->not->toBeNull();

    $this->governance->suspend($this->version, 'Dúvida clínica sobre o intervalo da campimetria.');

    expect($prefill())->toBeNull();
});
