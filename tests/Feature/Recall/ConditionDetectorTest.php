<?php

declare(strict_types=1);

/**
 * O detector de condições (ADR 0002, item 8): CID forte em prontuário ASSINADO ou medicação CONFIRMADA ativam a
 * condição; o que é fraco, ainda editável ou não confirmado só SUGERE; o excluído nunca entra; o que o médico já
 * decidiu (descartou, deu alta, escolheu à mão) o sistema nunca desfaz. Relógio fixo: 01/02/2026.
 */

use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus, RecallLifecycle as Life};
use App\Domains\Recall\Models\{PatientCondition, PatientRecall};
use App\Domains\Recall\Services\ProtocolGovernanceService;
use App\Enums\ClinicalSafety\MedicationStatus;
use App\Enums\Eye;
use App\Models\PatientMedication;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    $this->patient                                         = rcfPatient($this->entity);
});

function cdConditions(object $test, ?string $key = null)
{
    return PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $test->patient->id)->when($key, fn ($query) => $query->where('protocol_key', $key))->get();
}

// ── CID ─────────────────────────────────────────────────────────────────────

it('CID forte em prontuário ASSINADO ativa a condição: início na data da consulta, olho AO, evidência só com id/código/data', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2025-11-10', cids: ['H40.1'], signed: true);

    rcfEvaluate($this->entity, $this->patient);

    $condition = cdConditions($this, 'glaucoma')->sole();

    expect($condition->status)->toBe(ConditionStatus::Active)
        ->and($condition->origin)->toBe(ConditionOrigin::Cid)
        ->and($condition->eye)->toBe(Eye::Both)
        ->and($condition->started_on->toDateString())->toBe('2025-11-10')
        ->and($condition->evidence)->toBe(['records' => [(string) $record->id], 'code' => 'H401', 'first_on' => '2025-11-10', 'signed' => true])
        ->and($condition->confirmed_at)->toBeNull();

    // E o retorno por protocolo nasce na mesma avaliação (consulta em 4 meses do atendimento).
    expect(PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('source', 'protocol')->pluck('task_key')->sort()->values()->all())->toBe(['campimetria', 'consulta']);
});

it('CID forte em prontuário ainda EDITÁVEL só sugere (sem retorno); ao assinar, vira ativa', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2025-11-10', cids: ['H40.1']);

    rcfEvaluate($this->entity, $this->patient);

    expect(cdConditions($this, 'glaucoma')->sole()->status)->toBe(ConditionStatus::Suggested)
        ->and(PatientRecall::query()->withoutGlobalScopes()->where('source', 'protocol')->count())->toBe(0);

    $record->forceFill(['signed_at' => '2025-11-10 11:00:00', 'is_locked' => true])->saveQuietly();
    rcfEvaluate($this->entity, $this->patient);

    $condition = cdConditions($this, 'glaucoma')->sole();

    expect($condition->status)->toBe(ConditionStatus::Active)
        ->and($condition->started_on->toDateString())->toBe('2025-11-10')
        ->and($condition->evidence['signed'])->toBeTrue()
        ->and(PatientRecall::query()->withoutGlobalScopes()->where('source', 'protocol')->count())->toBe(2);
});

it('CID fraco (H40.7, H42.0) só SUGERE, mesmo assinado; CID excluído (H40.0, suspeita) não entra', function () {
    rcfAdopt($this->entity, 'glaucoma');

    foreach ([['H40.7', '2025-10-01'], ['H42.0', '2025-10-02']] as [$cid, $date]) {
        rcfRecord($this->entity, $this->patient, $this->doctor, $date, cids: [$cid], signed: true);
    }

    rcfEvaluate($this->entity, $this->patient);
    expect(cdConditions($this)->sole()->status)->toBe(ConditionStatus::Suggested);

    PatientCondition::query()->withoutGlobalScopes()->delete();

    $other = rcfPatient($this->entity);
    rcfRecord($this->entity, $other, $this->doctor, '2025-10-01', cids: ['H40.0'], signed: true);
    rcfEvaluate($this->entity, $other);

    expect(PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $other->id)->count())->toBe(0);
});

it('o código vem como o médico digitou: minúsculo, com ponto, espaço e marca de dupla codificação', function () {
    rcfAdopt($this->entity, 'retinopatia_diabetica');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: [' e11.3† '], signed: true);

    rcfEvaluate($this->entity, $this->patient);

    expect(cdConditions($this, 'retinopatia_diabetica')->sole())
        ->status->toBe(ConditionStatus::Active)
        ->evidence->toMatchArray(['code' => 'E113']);
});

it('vários prontuários: o início é o do mais ANTIGO assinado com o diagnóstico; a evidência junta até 5', function () {
    rcfAdopt($this->entity, 'glaucoma');

    $ids = [];

    foreach (['2025-12-01', '2025-09-01', '2025-10-15', '2025-07-01', '2025-08-01', '2025-11-01', '2025-06-01'] as $date) {
        $ids[$date] = (string) rcfRecord($this->entity, $this->patient, $this->doctor, $date, cids: ['H40.2'], signed: true)->id;
    }

    rcfEvaluate($this->entity, $this->patient);

    $condition = cdConditions($this, 'glaucoma')->sole();

    expect($condition->started_on->toDateString())->toBe('2025-06-01')
        ->and($condition->evidence['first_on'])->toBe('2025-06-01')
        ->and($condition->evidence['records'])->toHaveCount(5)
        ->and($condition->evidence['records'][0])->toBe($ids['2025-06-01']);
});

it('prontuário de outra clínica, removido ou no futuro (relativo à avaliação) não conta', function () {
    rcfAdopt($this->entity, 'glaucoma');

    $other = rcfClinic(recall: true);
    rcfRecord($other['entity'], $this->patient, $other['doctor'], '2025-10-01', cids: ['H40.1'], signed: true); // outra clínica

    $removed = rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-02', cids: ['H40.1'], signed: true);
    DB::table('medical_records')->where('id', $removed->id)->update(['deleted_at' => now()]); // prontuário assinado não se exclui pela tela: simula a correção de dado

    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-03-01', cids: ['H40.1'], signed: true); // depois de "hoje" (01/02)

    rcfEvaluate($this->entity, $this->patient);

    expect(cdConditions($this)->count())->toBe(0);
});

it('evidências de força diferente: a mais forte vence, com a data e o prontuário DELA', function () {
    rcfAdopt($this->entity, 'glaucoma');

    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-09-01', cids: ['H40.1'], signed: false); // forte, mas ainda editável: sugere
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.7'], signed: true);  // fraco, assinado: sugere
    $strong = rcfRecord($this->entity, $this->patient, $this->doctor, '2025-12-01', cids: ['H40.1'], signed: true); // forte e assinado: ativa

    rcfEvaluate($this->entity, $this->patient);

    $condition = cdConditions($this, 'glaucoma')->sole();

    expect($condition->status)->toBe(ConditionStatus::Active)
        ->and($condition->started_on->toDateString())->toBe('2025-12-01')
        ->and($condition->evidence['records'])->toBe([(string) $strong->id])
        ->and($condition->evidence['signed'])->toBeTrue();
});

// ── o que o médico decidiu ──────────────────────────────────────────────────

it('o que o médico decidiu o sistema nunca desfaz: descartada e com alta não voltam; manual não é tocada nem duplicada', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);

    $rejected = rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2025-10-01', ConditionStatus::Rejected);
    rcfEvaluate($this->entity, $this->patient);
    expect(cdConditions($this, 'glaucoma'))->toHaveCount(1)->and($rejected->fresh()->status)->toBe(ConditionStatus::Rejected);

    $rejected->forceFill(['status' => ConditionStatus::Discharged->value])->save();
    rcfEvaluate($this->entity, $this->patient);
    expect(cdConditions($this, 'glaucoma'))->toHaveCount(1);

    // Condição MANUAL aberta (olho direito, início escolhido): nada muda, nada duplica.
    $rejected->delete();
    $manual = PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'glaucoma', 'eye' => 'right',
        'status'    => ConditionStatus::Suggested->value, 'origin' => ConditionOrigin::Manual->value, 'started_on' => '2025-12-20',
    ]);
    rcfEvaluate($this->entity, $this->patient);

    $fresh = $manual->fresh();
    expect(cdConditions($this, 'glaucoma'))->toHaveCount(1)
        ->and($fresh->status)->toBe(ConditionStatus::Suggested)
        ->and($fresh->started_on->toDateString())->toBe('2025-12-20')
        ->and($fresh->eye)->toBe(Eye::Right);
});

it('a condição ATIVA confirmada pelo médico nunca é rebaixada; o início só adianta', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);
    rcfEvaluate($this->entity, $this->patient);

    $condition = cdConditions($this, 'glaucoma')->sole();
    $condition->forceFill(['confirmed_at' => now(), 'started_on' => '2025-11-15'])->save();

    rcfEvaluate($this->entity, $this->patient); // a evidência (01/10) é mais antiga: o início adianta
    expect($condition->fresh()->started_on->toDateString())->toBe('2025-10-01')->and($condition->fresh()->status)->toBe(ConditionStatus::Active);

    DB::table('medical_records')->where('id', $record->id)->update(['deleted_at' => now()]); // a evidência some: a condição ativa continua (o médico a confirmou)
    rcfEvaluate($this->entity, $this->patient);
    expect($condition->fresh()->status)->toBe(ConditionStatus::Active);
});

it('reavaliar sem nada novo não grava a condição', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);
    rcfEvaluate($this->entity, $this->patient);
    $condition = cdConditions($this, 'glaucoma')->sole();

    $this->travel(3)->hours();
    rcfEvaluate($this->entity, $this->patient);

    expect(cdConditions($this, 'glaucoma')->sole()->updated_at->equalTo($condition->updated_at))->toBeTrue();
});

it('dado de OUTRA clínica com o mesmo id de paciente nunca entra: condição, medicação', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfAdopt($this->entity, 'hidroxicloroquina');
    $other = rcfClinic(recall: true);

    // Uma decisão de outra clínica (descartada) não impede esta clínica de detectar...
    PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $other['entity']->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'glaucoma', 'eye' => 'both',
        'status'    => ConditionStatus::Rejected->value, 'origin' => ConditionOrigin::Manual->value, 'started_on' => '2025-09-01',
    ]);
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);

    // ...e uma medicação de outra clínica não vira condição desta.
    rcfMedication($other['entity'], $this->patient, 'Plaquenil (hidroxicloroquina 400 mg)', '2025-06-01', confirmed: true);

    rcfEvaluate($this->entity, $this->patient);

    $own = PatientCondition::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->where('patient_id', $this->patient->id)->get();

    expect($own->pluck('protocol_key')->all())->toBe(['glaucoma'])
        ->and($own->sole()->status)->toBe(ConditionStatus::Active);
});

it('a condição aberta do sistema é completada mesmo havendo outra decidida (alta de um olho)', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);

    PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'glaucoma', 'eye' => 'right',
        'status'    => ConditionStatus::Discharged->value, 'origin' => ConditionOrigin::Manual->value, 'started_on' => '2024-01-01',
    ]);
    $open = PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'glaucoma', 'eye' => 'both',
        'status'    => ConditionStatus::Suggested->value, 'origin' => ConditionOrigin::Cid->value, 'started_on' => null,
    ]);

    rcfEvaluate($this->entity, $this->patient);

    expect($open->fresh()->status)->toBe(ConditionStatus::Active)
        ->and($open->fresh()->started_on->toDateString())->toBe('2025-10-01')
        ->and(cdConditions($this, 'glaucoma'))->toHaveCount(2);
});

// ── medicação ───────────────────────────────────────────────────────────────

it('hidroxicloroquina em uso e CONFIRMADA ativa (início = início do uso); sem confirmação só sugere; sem data, "falta informação"', function () {
    rcfAdopt($this->entity, 'hidroxicloroquina');

    rcfMedication($this->entity, $this->patient, 'Plaquenil (hidroxicloroquina 400 mg)', '2025-06-01', confirmed: true);
    rcfEvaluate($this->entity, $this->patient);

    $condition = cdConditions($this, 'hidroxicloroquina')->sole();

    expect($condition->status)->toBe(ConditionStatus::Active)
        ->and($condition->origin)->toBe(ConditionOrigin::Medication)
        ->and($condition->started_on->toDateString())->toBe('2025-06-01')
        ->and($condition->evidence)->toMatchArray(['medication' => 'hydroxychloroquine', 'first_on' => '2025-06-01', 'confirmed' => true]);

    PatientCondition::query()->withoutGlobalScopes()->delete();
    PatientMedicationReset();

    $unconfirmed = rcfPatient($this->entity);
    rcfMedication($this->entity, $unconfirmed, 'Hidroxicloroquina', '2025-06-01', confirmed: false);
    rcfEvaluate($this->entity, $unconfirmed);
    expect(PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $unconfirmed->id)->sole()->status)->toBe(ConditionStatus::Suggested);

    $noDate = rcfPatient($this->entity);
    rcfMedication($this->entity, $noDate, 'Hidroxicloroquina 200 mg', null, confirmed: true);
    rcfEvaluate($this->entity, $noDate);

    $row = PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $noDate->id)->sole();
    expect($row->status)->toBe(ConditionStatus::Active)->and($row->started_on)->toBeNull();
    expect(PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $noDate->id)->pluck('lifecycle')->unique()->all())->toBe([Life::NeedsInfo]);
});

it('só palavra inteira: "cloroquina" não é hidroxicloroquina; medicação suspensa, descartada ou removida não conta', function () {
    rcfAdopt($this->entity, 'hidroxicloroquina');

    rcfMedication($this->entity, $this->patient, 'Cloroquina 150 mg');
    rcfMedication($this->entity, $this->patient, 'Hidroxicloroquina 400 mg', status: MedicationStatus::Suspended);
    rcfMedication($this->entity, $this->patient, 'Hidroxicloroquina 200 mg', status: MedicationStatus::Discarded);

    $removed = rcfMedication($this->entity, $this->patient, 'Hidroxicloroquina 300 mg'); // em uso e confirmada...
    DB::table('patient_medications')->where('id', $removed->id)->update(['deleted_at' => now()]); // ...mas removida do cadastro

    rcfEvaluate($this->entity, $this->patient);

    expect(cdConditions($this)->count())->toBe(0);
});

// ── protocolo que não vale ──────────────────────────────────────────────────

it('protocolo NÃO adotado, desativado ou com a versão suspensa não detecta nada', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);

    rcfEvaluate($this->entity, $this->patient);
    expect(cdConditions($this)->count())->toBe(0); // nenhuma adoção

    $adoption = rcfAdopt($this->entity, 'glaucoma');
    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Dúvida clínica sobre o intervalo da campimetria.');
    rcfEvaluate($this->entity, $this->patient);
    expect(cdConditions($this)->count())->toBe(0); // versão suspensa: o freio de mão vale também aqui

    app(ProtocolGovernanceService::class)->resume($adoption->version->fresh());
    $adoption->forceFill(['deactivated_at' => now()])->save();
    rcfEvaluate($this->entity, $this->patient);
    expect(cdConditions($this)->count())->toBe(0);
});

it('cada protocolo adotado detecta o seu: glaucoma e retinopatia no mesmo paciente', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfAdopt($this->entity, 'retinopatia_diabetica');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-10-01', cids: ['H40.1', 'E11.3'], signed: true);

    rcfEvaluate($this->entity, $this->patient);

    expect(cdConditions($this)->pluck('protocol_key')->sort()->values()->all())->toBe(['glaucoma', 'retinopatia_diabetica'])
        ->and(cdConditions($this)->pluck('status')->unique()->all())->toBe([ConditionStatus::Active]);
});

function PatientMedicationReset(): void
{
    PatientMedication::query()->withoutGlobalScopes()->forceDelete();
}
