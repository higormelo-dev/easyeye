<?php

declare(strict_types=1);

/**
 * O avaliador do recall (ADR 0002, item 7): lê o prontuário do paciente, aplica os protocolos que a clínica
 * ADOTOU e grava `patient_recalls`. Idempotente, sempre derivado e fiel ao que a clínica decidiu à mão.
 * O relógio é um parâmetro (`2026-02-01` por padrão): nada aqui depende de "hoje".
 */

use App\Domains\Recall\Enums\{ConditionStatus, RecallLifecycle as Life};
use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent, RecallClinicProtocol, RecallClinicSettings};
use App\Domains\Recall\Services\ProtocolGovernanceService;
use App\Enums\{Eye, ScheduleSituation};
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic();
    $this->patient                                         = rcfPatient($this->entity);
});

function reEvents(PatientRecall $row, ?string $event = null)
{
    return PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $row->id)->when($event, fn ($query) => $query->where('event', $event))->get();
}

// ── o retorno pedido pelo médico ────────────────────────────────────────────

it('o retorno que o médico pediu vira uma linha: vence no dia do atendimento + dias, com os prazos operacionais', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);

    $stats = rcfEvaluate($this->entity, $this->patient);
    $row   = rcfRecalls($this->patient)->get('doctor_order');

    expect($stats)->toBe(['created' => 1, 'updated' => 0, 'closed' => 0, 'unchanged' => 0])
        ->and($row->source)->toBe('doctor_order')
        ->and($row->patient_condition_id)->toBeNull()
        ->and($row->protocol_version_id)->toBeNull()
        ->and($row->lifecycle)->toBe(Life::Open)
        ->and($row->anchor_at->toDateString())->toBe('2026-01-10')
        ->and($row->last_done_at->toDateString())->toBe('2026-01-10')
        ->and($row->due_at->toDateString())->toBe('2026-04-10')
        ->and($row->invite_from->toDateString())->toBe('2026-03-11')
        ->and($row->overdue_from->toDateString())->toBe('2026-05-11')
        ->and($row->lost_from->toDateString())->toBe('2026-10-08')
        ->and($row->evaluator_version)->toBe(1)
        ->and($row->meta['follow_up_days'])->toBe(90)
        ->and(reEvents($row, 'calculated'))->toHaveCount(1);
});

it('reavaliar sem nada novo NÃO grava: nem a linha, nem um evento, nem o updated_at', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);

    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)->get('doctor_order');

    $this->travel(2)->hours();
    $stats = rcfEvaluate($this->entity, $this->patient);

    expect($stats)->toBe(['created' => 0, 'updated' => 0, 'closed' => 0, 'unchanged' => 1])
        ->and(rcfRecalls($this->patient)->get('doctor_order')->updated_at->equalTo($row->updated_at))->toBeTrue()
        ->and(PatientRecallEvent::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(PatientRecall::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('sem retorno pedido (follow_up_days vazio ou zero) não há linha', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', null);

    expect(rcfEvaluate($this->entity, $this->patient))->toBe(['created' => 0, 'updated' => 0, 'closed' => 0, 'unchanged' => 0])
        ->and(PatientRecall::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('vale o retorno do atendimento MAIS RECENTE: um atendimento novo sem retorno pedido encerra o anterior', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);

    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-25', null);
    $stats = rcfEvaluate($this->entity, $this->patient);
    $row   = rcfRecalls($this->patient)->get('doctor_order');

    expect($stats['closed'])->toBe(1)
        ->and($row->lifecycle)->toBe(Life::Closed)
        ->and($row->closed_reason)->toBe('doctor_order_ended')
        ->and($row->scheduled_schedule_id)->toBeNull()
        ->and(reEvents($row, 'closed')->sole()->meta)->toBe(['reason' => 'doctor_order_ended']);
});

it('desligar o retorno pedido pelo médico na clínica encerra a linha com o motivo certo; religar a reabre', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);

    $settings = RecallClinicSettings::forEntity((string) $this->entity->id);
    $settings->fill(['doctor_order_enabled' => false, 'invite_weekdays' => [1, 2, 3, 4, 5]])->save();

    rcfEvaluate($this->entity, $this->patient);
    expect(rcfRecalls($this->patient)->get('doctor_order'))
        ->lifecycle->toBe(Life::Closed)
        ->closed_reason->toBe('doctor_order_disabled');

    $settings->fill(['doctor_order_enabled' => true])->save();
    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)->get('doctor_order'))
        ->lifecycle->toBe(Life::Open)
        ->closed_reason->toBeNull();
});

// ── o ciclo: o que a clínica decidiu sobrevive; o paciente que volta recomeça ─

it('o que a clínica decidiu (dispensar, adiar) sobrevive à reavaliação; o adiamento que venceu volta sozinho', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)->get('doctor_order');

    $row->forceFill(['lifecycle' => Life::Dismissed->value, 'dismissed_reason' => 'Foi para outro estado'])->save();
    $stats = rcfEvaluate($this->entity, $this->patient);

    expect($stats['unchanged'])->toBe(1)
        ->and($row->fresh()->lifecycle)->toBe(Life::Dismissed)
        ->and($row->fresh()->dismissed_reason)->toBe('Foi para outro estado');

    $row->forceFill(['lifecycle' => Life::Snoozed->value, 'dismissed_reason' => null, 'snoozed_until' => '2026-03-01', 'contact_count' => 2])->save();

    rcfEvaluate($this->entity, $this->patient, '2026-02-28');
    expect($row->fresh()->lifecycle)->toBe(Life::Snoozed);

    rcfEvaluate($this->entity, $this->patient, '2026-03-01');
    expect($row->fresh())
        ->lifecycle->toBe(Life::Open)
        ->snoozed_until->toBeNull()
        ->contact_count->toBe(2); // o adiamento acabou, o histórico de contatos continua
});

it('o paciente voltou (ato novo): o ciclo recomeça — adiamento, dispensa e contatos do ciclo anterior saem, novo vencimento, evento', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)->get('doctor_order');
    $row->forceFill(['lifecycle' => Life::Snoozed->value, 'snoozed_until' => '2026-06-01', 'contact_count' => 3, 'last_contact_at' => now()])->save();

    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-04-15', 180);
    $stats = rcfEvaluate($this->entity, $this->patient, '2026-04-16');

    expect($stats['updated'])->toBe(1)
        ->and($row->fresh())
        ->lifecycle->toBe(Life::Open)
        ->snoozed_until->toBeNull()
        ->contact_count->toBe(0)
        ->due_at->toDateString()->toBe('2026-10-12')
        ->last_done_at->toDateString()->toBe('2026-04-15')
        ->and(reEvents($row, 'cycle_completed'))->toHaveCount(1);
});

it('a linha dispensada volta a abrir quando o paciente volta (a dispensa valia para o ciclo anterior)', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)->get('doctor_order');
    $row->forceFill(['lifecycle' => Life::Dismissed->value, 'dismissed_reason' => 'Não quer mais voltar'])->save();

    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-05-01', 60);
    rcfEvaluate($this->entity, $this->patient, '2026-05-02');

    expect($row->fresh())->lifecycle->toBe(Life::Open)->dismissed_reason->toBeNull();
});

// ── "feito fora" ────────────────────────────────────────────────────────────

it('"feito fora" depois do atendimento encerra o retorno pedido pelo médico (feito em outro serviço)', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)->get('doctor_order');

    $row->forceFill(['external_done_at' => '2026-03-05'])->save();
    rcfEvaluate($this->entity, $this->patient, '2026-03-06');

    expect($row->fresh())->lifecycle->toBe(Life::Closed)->closed_reason->toBe('done_elsewhere');

    // Atendimento novo aqui: o retorno pedido nele vale de novo e o "feito fora" antigo sai.
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-04-01', 30);
    rcfEvaluate($this->entity, $this->patient, '2026-04-02');

    expect($row->fresh())
        ->lifecycle->toBe(Life::Open)
        ->closed_reason->toBeNull()
        ->due_at->toDateString()->toBe('2026-05-01')
        ->external_done_at->toBeNull();
});

it('"feito fora" num exame de UM olho re-ancora só o retorno daquele olho: o outro segue pelo que foi feito aqui', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $right = rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Right, '2025-01-15');
    $left  = rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Left, '2025-01-15');
    rcfEvaluate($this->entity, $this->patient);

    $campimetria = fn (string $conditionId): PatientRecall => PatientRecall::query()->withoutGlobalScopes()
        ->where('patient_id', $this->patient->id)->where('patient_condition_id', $conditionId)->where('task_key', 'campimetria')->sole();

    $campimetria((string) $right->id)->forceFill(['external_done_at' => '2026-01-20'])->save();
    rcfEvaluate($this->entity, $this->patient);

    expect($campimetria((string) $right->id)->last_done_at->toDateString())->toBe('2026-01-20')
        ->and($campimetria((string) $left->id)->last_done_at)->toBeNull();
});

// ── protocolo adotado ───────────────────────────────────────────────────────

it('condição ATIVA de protocolo adotado gera uma linha por tarefa LIGADA, contada do início; a opcional desligada não existe', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');

    $stats = rcfEvaluate($this->entity, $this->patient);
    $rows  = rcfRecalls($this->patient);

    expect($stats['created'])->toBe(2)
        ->and($rows->keys()->sort()->values()->all())->toBe(['campimetria', 'consulta'])
        ->and($rows['consulta'])
        ->source->toBe('protocol')
        ->protocol_key->toBe('glaucoma')
        ->protocol_version_id->toBe((string) $adoption->version_id)
        ->kind->toBe('consultation')
        ->due_at->toDateString()->toBe('2026-05-15')
        ->last_done_at->toBeNull()
        ->anchor_at->toDateString()->toBe('2026-01-15')
        ->and($rows['campimetria'])
        ->kind->toBe('exam')
        ->exam_key->toBe('visual_field')
        ->due_at->toDateString()->toBe('2027-01-15');
});

it('o último atendimento e o último exame MAPEADO viram a âncora (o padrão da clínica; olho coberto)', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-02-20');

    $campimetria = rcfExamType();
    rcfMapExam($this->entity, 'visual_field', $campimetria);
    rcfExam($this->patient, $campimetria, '2026-03-01', 0);
    // Um exame que a clínica NÃO mapeou não conta, e um desativado também não.
    rcfExam($this->patient, rcfExamType('Topografia'), '2026-03-10', 0);
    rcfExam($this->patient, $campimetria, '2026-03-20', 0, active: false);

    rcfEvaluate($this->entity, $this->patient, '2026-03-25');
    $rows = rcfRecalls($this->patient);

    expect($rows['consulta'])->last_done_at->toDateString()->toBe('2026-02-20')->due_at->toDateString()->toBe('2026-06-20')
        ->and($rows['campimetria'])->last_done_at->toDateString()->toBe('2026-03-01')->due_at->toDateString()->toBe('2027-03-01');
});

it('exame só de um olho não cobre condição nos dois olhos; os dois olhos juntos usam a data do mais antigo', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    $campimetria = rcfExamType();
    rcfMapExam($this->entity, 'visual_field', $campimetria);

    rcfExam($this->patient, $campimetria, '2026-03-01', 1); // só OD
    rcfEvaluate($this->entity, $this->patient, '2026-03-25');
    expect(rcfRecalls($this->patient)['campimetria'])->last_done_at->toBeNull()->due_at->toDateString()->toBe('2027-01-15');

    rcfExam($this->patient, $campimetria, '2026-03-10', 2); // OE depois
    rcfEvaluate($this->entity, $this->patient, '2026-03-25');
    expect(rcfRecalls($this->patient)['campimetria'])->last_done_at->toDateString()->toBe('2026-03-01')->due_at->toDateString()->toBe('2027-03-01');
});

it('o intervalo que a clínica ajustou (dentro da faixa) e a tarefa opcional que ela ligou valem', function () {
    rcfAdopt($this->entity, 'glaucoma', ['campimetria' => ['interval' => 6], 'oct_nervo_optico' => ['enabled' => true]]);
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');

    rcfEvaluate($this->entity, $this->patient);
    $rows = rcfRecalls($this->patient);

    expect($rows->keys()->sort()->values()->all())->toBe(['campimetria', 'consulta', 'oct_nervo_optico'])
        ->and($rows['campimetria']->due_at->toDateString())->toBe('2026-07-15')
        ->and($rows['campimetria']->meta['interval'])->toBe(6)
        ->and($rows['oct_nervo_optico']->due_at->toDateString())->toBe('2027-01-15');
});

it('intervalo fora da faixa validada (parâmetro adulterado) NUNCA derruba a avaliação: usa o padrão e sinaliza', function () {
    rcfAdopt($this->entity, 'glaucoma', ['consulta' => ['interval' => 1]]); // a faixa é de 3 a 6 meses
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');

    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)['consulta'];

    expect($row->due_at->toDateString())->toBe('2026-05-15')
        ->and($row->meta['interval_fallback'])->toBeTrue();
});

it('condição sem início (started_on) fica "falta informação" — sem datas, sem convite — e abre quando o início é informado', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $condition = rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, null);

    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)['consulta'];

    expect($row->lifecycle)->toBe(Life::NeedsInfo)
        ->and($row->due_at)->toBeNull()
        ->and($row->invite_from)->toBeNull()
        ->and($row->meta['missing'])->toBe('started_on');

    $condition->forceFill(['started_on' => '2026-01-15'])->save();
    rcfEvaluate($this->entity, $this->patient);

    expect($row->fresh())->lifecycle->toBe(Life::Open)->due_at->toDateString()->toBe('2026-05-15');
});

it('condição só SUGERIDA ou de protocolo não adotado não gera retorno; ao desativar a adoção as linhas se encerram', function () {
    $suggested = rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15', ConditionStatus::Suggested);
    $adoption  = rcfAdopt($this->entity, 'glaucoma');

    expect(rcfEvaluate($this->entity, $this->patient)['created'])->toBe(0); // sugerida: o médico ainda não confirmou

    rcfCondition($this->entity, $this->patient, 'dmri', Eye::Right, '2026-01-15');
    expect(rcfEvaluate($this->entity, $this->patient)['created'])->toBe(0); // a DMRI não foi adotada pela clínica

    $suggested->forceFill(['status' => ConditionStatus::Active->value])->save(); // o médico confirma
    rcfEvaluate($this->entity, $this->patient);
    expect(rcfRecalls($this->patient))->toHaveCount(2);

    $adoption->forceFill(['deactivated_at' => now()])->save();
    $stats = rcfEvaluate($this->entity, $this->patient);

    expect($stats['closed'])->toBe(2)
        ->and(rcfRecalls($this->patient)->pluck('closed_reason')->unique()->all())->toBe(['protocol_deactivated']);
});

it('alta ou descarte da condição encerra as linhas ("condição encerrada"); tarefa desligada pela clínica também, com outro motivo', function () {
    rcfAdopt($this->entity, 'glaucoma', ['oct_nervo_optico' => ['enabled' => true]]);
    $condition = rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    rcfEvaluate($this->entity, $this->patient);
    expect(rcfRecalls($this->patient))->toHaveCount(3);

    // A clínica desliga a tarefa opcional (nova adoção com a tarefa desligada).
    RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->get()->each(function ($adoption) {
        $parameters                                         = $adoption->parameters;
        $parameters['tasks']['oct_nervo_optico']['enabled'] = false;
        $adoption->forceFill(['parameters' => $parameters])->save();
    });

    rcfEvaluate($this->entity, $this->patient);
    expect(rcfRecalls($this->patient)['oct_nervo_optico'])->lifecycle->toBe(Life::Closed)->closed_reason->toBe('task_disabled')
        ->and(rcfRecalls($this->patient)['consulta']->lifecycle)->toBe(Life::Open);

    $condition->forceFill(['status' => ConditionStatus::Discharged->value, 'discharged_at' => now()])->save();
    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)->pluck('lifecycle')->unique()->all())->toBe([Life::Closed])
        ->and(rcfRecalls($this->patient)['consulta']->closed_reason)->toBe('condition_ended');
});

it('tarefa de uma vez só já feita (HCQ basal) fica ENCERRADA como concluída; a triagem anual só começa depois dos 60 meses', function () {
    rcfAdopt($this->entity, 'hidroxicloroquina');
    rcfCondition($this->entity, $this->patient, 'hidroxicloroquina', Eye::Both, '2026-01-15');
    $campimetria = rcfExamType();
    rcfMapExam($this->entity, 'visual_field', $campimetria);
    rcfExam($this->patient, $campimetria, '2026-02-10', 0);

    rcfEvaluate($this->entity, $this->patient, '2026-03-01');
    $rows = rcfRecalls($this->patient);

    expect($rows['basal_campimetria'])->lifecycle->toBe(Life::Closed)->closed_reason->toBe('completed')->due_at->toBeNull()
        ->and($rows['basal_oct_macula']->lifecycle)->toBe(Life::Open)
        ->and($rows['triagem_campimetria']->due_at->toDateString())->toBe('2031-01-15'); // 60 meses depois do início
});

// ── o freio de mão: versão suspensa, adoção divergente ──────────────────────

it('versão SUSPENSA congela as linhas (rule_suspended, datas intactas, nada novo nasce); retomar devolve', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    rcfEvaluate($this->entity, $this->patient);

    $governance = app(ProtocolGovernanceService::class);
    $version    = $adoption->version->fresh();
    $governance->suspend($version, 'Dúvida clínica sobre o intervalo da campimetria.');

    $stats = rcfEvaluate($this->entity, $this->patient);
    $rows  = rcfRecalls($this->patient);

    expect($stats['updated'])->toBe(2)
        ->and($rows['consulta']->rule_suspended)->toBeTrue()
        ->and($rows['consulta']->lifecycle)->toBe(Life::Open)
        ->and($rows['consulta']->due_at->toDateString())->toBe('2026-05-15')
        ->and(reEvents($rows['consulta'], 'suspended'))->toHaveCount(1);

    // Reavaliar de novo não regrava nem repete o evento.
    expect(rcfEvaluate($this->entity, $this->patient)['updated'])->toBe(0)
        ->and(reEvents($rows['consulta'], 'suspended'))->toHaveCount(1);

    $governance->resume($version->fresh());
    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)['consulta']->rule_suspended)->toBeFalse()
        ->and(reEvents($rows['consulta'], 'resumed'))->toHaveCount(1);
});

it('com a versão suspensa desde o início, nenhuma linha de protocolo nasce (e o retorno do médico segue valendo)', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Suspensa antes de qualquer cálculo, motivo longo.');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-20', 45);

    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)->keys()->all())->toBe(['doctor_order']);
});

it('adoção com o hash diferente do da versão (conteúdo trocado por baixo) é tratada como suspensa', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    rcfEvaluate($this->entity, $this->patient);

    $adoption->forceFill(['adopted_content_hash' => '1:' . str_repeat('a', 64)])->save();
    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)['consulta']->rule_suspended)->toBeTrue();
});

// ── o limite validado, o agendamento, o paciente e a clínica ────────────────

it('o retorno pedido acima do máximo validado é LIMITADO e sinalizado (cap_to_max)', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2025-12-01');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 720);

    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)['doctor_order'];

    expect($row->due_at->toDateString())->toBe('2026-07-10') // consulta + 6 meses (o máximo da faixa)
        ->and($row->meta['capped'])->toBeTrue()
        ->and($row->meta['follow_up_days'])->toBe(720);
});

it('o próximo agendamento ABERTO do paciente vincula o retorno; cancelado/atendido desvincula', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-20 09:00:00']);

    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)['doctor_order'];

    expect($row->scheduled_schedule_id)->toBe((string) $schedule->id);

    $schedule->forceFill(['situation' => ScheduleSituation::Cancelled->value])->saveQuietly();
    rcfEvaluate($this->entity, $this->patient);

    expect($row->fresh()->scheduled_schedule_id)->toBeNull();
});

it('um agendamento de OUTRA clínica, mesmo de paciente com o mesmo id, nunca vincula', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    $other = rcfClinic();
    createScheduleForEntity($other['entity'], ['patient_id' => $this->patient->id, 'date_time' => '2026-03-20 09:00:00']);

    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)['doctor_order']->scheduled_schedule_id)->toBeNull();
});

it('o retorno pedido é limitado pelo MENOR máximo entre os protocolos da paciente (glaucoma 6 meses, DMRI 12)', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfAdopt($this->entity, 'dmri');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2025-12-01');
    rcfCondition($this->entity, $this->patient, 'dmri', Eye::Right, '2025-12-01');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 720);

    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)['doctor_order']->due_at->toDateString())->toBe('2026-07-10');
});

it('o vínculo que a clínica fez com um agendamento ABERTO sobrevive; se ele deixa de estar aberto, vale o próximo aberto', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    ['schedule' => $earlier] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-20 09:00:00']);
    ['schedule' => $chosen]  = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-04-20 09:00:00']);

    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)['doctor_order'];
    expect($row->scheduled_schedule_id)->toBe((string) $earlier->id); // sem escolha da clínica: o mais próximo

    $row->forceFill(['scheduled_schedule_id' => $chosen->id])->save(); // a clínica escolheu este
    rcfEvaluate($this->entity, $this->patient);
    expect($row->fresh()->scheduled_schedule_id)->toBe((string) $chosen->id);

    $chosen->forceFill(['situation' => ScheduleSituation::Cancelled->value])->saveQuietly();
    rcfEvaluate($this->entity, $this->patient);
    expect($row->fresh()->scheduled_schedule_id)->toBe((string) $earlier->id);
});

it('a âncora do atendimento é a data do agendamento vinculado; excluído o agendamento, cai para a data do registro', function () {
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-01-10 09:00:00']);
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-14', 90, $schedule); // registro lançado 4 dias depois

    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)['doctor_order'];

    expect($row->anchor_at->toDateString())->toBe('2026-01-10')
        ->and($row->due_at->toDateString())->toBe('2026-04-10');

    $schedule->delete();
    rcfEvaluate($this->entity, $this->patient);

    expect($row->fresh()->anchor_at->toDateString())->toBe('2026-01-14')
        ->and($row->fresh()->due_at->toDateString())->toBe('2026-04-14');
});

it('a clínica que muda o intervalo (dentro da faixa) recalcula as datas e fica registrado ("datas recalculadas")', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    rcfEvaluate($this->entity, $this->patient);
    $row = rcfRecalls($this->patient)['campimetria'];

    expect($row->due_at->toDateString())->toBe('2027-01-15');

    $parameters                                     = $adoption->parameters;
    $parameters['tasks']['campimetria']['interval'] = 6;
    $adoption->forceFill(['parameters' => $parameters])->save();

    $stats = rcfEvaluate($this->entity, $this->patient);

    expect($stats['updated'])->toBe(1)
        ->and($row->fresh()->due_at->toDateString())->toBe('2026-07-15')
        ->and(reEvents($row, 'dates_changed')->sole()->meta)->toBe(['from' => '2027-01-15', 'to' => '2026-07-15']);
});

it('o mapeamento de exames é da clínica: o de outra clínica para o mesmo tipo de exame não conta', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    $campimetria = rcfExamType();

    $other = rcfClinic();
    rcfMapExam($other['entity'], 'visual_field', $campimetria); // outra clínica mapeou; esta não
    rcfExam($this->patient, $campimetria, '2026-03-01', 0);

    rcfEvaluate($this->entity, $this->patient, '2026-03-25');

    expect(rcfRecalls($this->patient)['campimetria']->last_done_at)->toBeNull();
});

it('atendimento no FUTURO (relativo à data da avaliação) não vira âncora; agendamento futuro vinculado cai para a data do registro', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-03-01', 90); // depois de "hoje" (2026-02-01)

    expect(rcfEvaluate($this->entity, $this->patient)['created'])->toBe(0);

    ['schedule' => $future] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-10 09:00:00']);
    $second                 = rcfPatient($this->entity);
    rcfRecord($this->entity, $second, $this->doctor, '2026-01-20', 90, $future); // lançado antes de "hoje", agendado para depois

    rcfEvaluate($this->entity, $second);

    expect(rcfRecalls($second)['doctor_order']->anchor_at->toDateString())->toBe('2026-01-20');
});

it('agendamento EXCLUÍDO nunca vincula o retorno', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-20 09:00:00']);
    $schedule->delete();

    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)['doctor_order']->scheduled_schedule_id)->toBeNull();
});

it('conteúdo da versão adulterado (o hash não bate com o texto) é tratado como suspenso: nada novo, linhas paradas', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');

    $original = (string) $adoption->version->content;

    crfTamperVersionContent($adoption->version);
    rcfEvaluate($this->entity, $this->patient);

    expect(PatientRecall::query()->withoutGlobalScopes()->count())->toBe(0);

    // E com linhas já calculadas, elas ficam paradas (não somem, não recalculam).
    DB::unprepared('ALTER TABLE recall_protocol_versions DISABLE TRIGGER recall_protocol_versions_guard');
    DB::table('recall_protocol_versions')->where('id', $adoption->version_id)->update(['content' => $original]);
    DB::unprepared('ALTER TABLE recall_protocol_versions ENABLE TRIGGER recall_protocol_versions_guard');

    expect($adoption->version->fresh()->integrityOk())->toBeTrue();
    rcfEvaluate($this->entity, $this->patient);
    expect(rcfRecalls($this->patient))->toHaveCount(2);

    crfTamperVersionContent($adoption->version);
    $stats = rcfEvaluate($this->entity, $this->patient);

    expect($stats['updated'])->toBe(2)
        ->and(rcfRecalls($this->patient)->pluck('rule_suspended')->unique()->all())->toBe([true]);
});

it('paciente removido encerra tudo ("paciente removido"); restaurado, o retorno volta', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);

    $this->patient->delete();
    $stats = rcfEvaluate($this->entity, $this->patient);

    expect($stats['closed'])->toBe(1)
        ->and(rcfRecalls($this->patient)['doctor_order']->closed_reason)->toBe('patient_removed');

    $this->patient->restore();
    rcfEvaluate($this->entity, $this->patient);

    expect(rcfRecalls($this->patient)['doctor_order'])->lifecycle->toBe(Life::Open)->closed_reason->toBeNull();
});

it('isolamento: avaliar com a clínica errada não cria nada e não toca nas linhas da clínica certa', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $this->patient);
    $before = rcfRecalls($this->patient)['doctor_order'];

    $other = rcfClinic();
    $stats = rcfEvaluate($other['entity'], $this->patient);

    expect($stats)->toBe(['created' => 0, 'updated' => 0, 'closed' => 0, 'unchanged' => 0])
        ->and(PatientRecall::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(rcfRecalls($this->patient)['doctor_order']->updated_at->equalTo($before->updated_at))->toBeTrue();
});

it('a avaliação lê o dado da PRÓPRIA clínica: atendimento de outra clínica, do mesmo paciente, não entra', function () {
    $other = rcfClinic();
    rcfRecord($other['entity'], $this->patient, $other['doctor'], '2026-01-10', 90);

    rcfEvaluate($this->entity, $this->patient);

    expect(PatientRecall::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('adoção sobre versão que NÃO está em uso (só validada) nunca calcula: o avaliador não confia só na adoção', function () {
    $validated = crfValidatedVersion('glaucoma')['version'];
    rcfAdopt($this->entity, 'glaucoma', version: $validated);
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');

    rcfEvaluate($this->entity, $this->patient);

    expect($validated->state->isInUse())->toBeFalse()
        ->and(PatientRecall::query()->withoutGlobalScopes()->count())->toBe(0);
});
