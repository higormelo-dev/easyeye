<?php

declare(strict_types=1);

/**
 * Quando a clínica ADOTA um protocolo, quem já tem o diagnóstico (CID no prontuário) ou a medicação entra na fila
 * de avaliação sem ter, ainda, condição nem retorno: é o "backfill" da ativação. Aqui: o que conta como candidato,
 * o que NÃO conta, e o caminho completo ativar → fila → dreno → condição → retorno.
 */

use App\Domains\Recall\Models\{PatientCondition, PatientRecall};
use App\Domains\Recall\Services\{ProtocolGovernanceService, RecallCandidates, RecallDirtyDrainer};
use App\Enums\ClinicalSafety\MedicationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
});

function rctIds(object $test): array
{
    return RecallCandidates::patients((string) $test->entity->id, CarbonImmutable::parse('2026-02-01'))->pluck('p.id')->map(fn ($id) => (string) $id)->all();
}

it('o que os protocolos adotados reconhecem: prefixos fortes e fracos de CID e os princípios ativos; o excluído fica de fora', function () {
    expect(RecallCandidates::tracked((string) $this->entity->id))->toBe(['cid' => [], 'meds' => []]);

    rcfAdopt($this->entity, 'glaucoma');
    rcfAdopt($this->entity, 'hidroxicloroquina');

    $tracked = RecallCandidates::tracked((string) $this->entity->id);

    expect($tracked['cid'])->toContain('H401%', 'H409%', 'H40%', 'H42%')
        ->and(array_values(array_intersect($tracked['cid'], ['H400%'])))->toBe([])
        ->and($tracked['meds'])->toBe(['%hidroxicloroquina%']);
});

it('versão suspensa ou adoção desativada não é reconhecida', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Dúvida clínica sobre o intervalo da campimetria.');

    expect(RecallCandidates::tracked((string) $this->entity->id))->toBe(['cid' => [], 'meds' => []]);

    app(ProtocolGovernanceService::class)->resume($adoption->version->fresh());
    $adoption->forceFill(['deactivated_at' => now()])->save();

    expect(RecallCandidates::tracked((string) $this->entity->id))->toBe(['cid' => [], 'meds' => []]);
});

it('candidato por DIAGNÓSTICO: CID de um protocolo adotado (com ponto ou sem), nunca outro CID, nem o de outra clínica', function () {
    rcfAdopt($this->entity, 'glaucoma');

    $match = rcfPatient($this->entity);
    rcfRecord($this->entity, $match, $this->doctor, '2025-10-01', cids: ['h40.1'], signed: true);

    $weak = rcfPatient($this->entity);
    rcfRecord($this->entity, $weak, $this->doctor, '2025-10-01', cids: ['H42.0']);

    $other = rcfPatient($this->entity);
    rcfRecord($this->entity, $other, $this->doctor, '2025-10-01', cids: ['H35.3', 'E11.9'], signed: true); // outro protocolo / outro quadro

    $removed = rcfPatient($this->entity);
    $record  = rcfRecord($this->entity, $removed, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);
    DB::table('medical_records')->where('id', $record->id)->update(['deleted_at' => now()]);

    $elsewhere = rcfClinic(recall: true);
    $stranger  = rcfPatient($elsewhere['entity']);
    rcfRecord($elsewhere['entity'], $stranger, $elsewhere['doctor'], '2025-10-01', cids: ['H40.1'], signed: true);

    $ids = rctIds($this);

    expect($ids)->toContain((string) $match->id, (string) $weak->id)
        ->and(array_values(array_intersect($ids, [(string) $other->id, (string) $removed->id, (string) $stranger->id])))->toBe([]);
});

it('diagnosis_cids que não é lista (objeto, texto) não derruba a consulta nem entra', function () {
    rcfAdopt($this->entity, 'glaucoma');

    $odd    = rcfPatient($this->entity);
    $record = rcfRecord($this->entity, $odd, $this->doctor, '2025-10-01');
    DB::statement("UPDATE medical_records SET diagnosis_cids = '{\"code\": \"H40.1\"}'::json WHERE id = ?", [$record->id]);

    $text       = rcfPatient($this->entity);
    $textRecord = rcfRecord($this->entity, $text, $this->doctor, '2025-10-01');
    DB::statement("UPDATE medical_records SET diagnosis_cids = '\"H40.1\"'::json WHERE id = ?", [$textRecord->id]);

    $ok = rcfPatient($this->entity);
    rcfRecord($this->entity, $ok, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);

    $ids = rctIds($this);

    expect($ids)->toContain((string) $ok->id)
        ->and(array_values(array_intersect($ids, [(string) $odd->id, (string) $text->id])))->toBe([]);
});

it('candidato por MEDICAÇÃO: princípio ativo em uso; descartada ou suspensa e outra substância não', function () {
    rcfAdopt($this->entity, 'hidroxicloroquina');

    $uses = rcfPatient($this->entity);
    rcfMedication($this->entity, $uses, 'Plaquenil (hidroxicloroquina 400 mg)');

    $stopped = rcfPatient($this->entity);
    rcfMedication($this->entity, $stopped, 'Hidroxicloroquina', status: MedicationStatus::Discarded);

    $different = rcfPatient($this->entity);
    rcfMedication($this->entity, $different, 'Dipirona 500 mg');

    $ids = rctIds($this);

    expect($ids)->toContain((string) $uses->id)
        ->and(array_values(array_intersect($ids, [(string) $stopped->id, (string) $different->id])))->toBe([]);
});

it('sem protocolo adotado, ter o CID não torna o paciente candidato (só o retorno pedido pelo médico, as linhas e as condições)', function () {
    $patient = rcfPatient($this->entity);
    rcfRecord($this->entity, $patient, $this->doctor, '2025-10-01', cids: ['H40.1'], signed: true);

    expect(rctIds($this))->not->toContain((string) $patient->id);
});

it('o caminho completo: ativar o protocolo põe quem tem o diagnóstico na fila; o dreno cria a condição e o retorno', function () {
    $patient = rcfPatient($this->entity);
    rcfRecord($this->entity, $patient, $this->doctor, now()->subDays(200)->toDateString(), cids: ['H40.2'], signed: true);
    $unrelated = rcfPatient($this->entity);
    rcfRecord($this->entity, $unrelated, $this->doctor, now()->subDays(200)->toDateString(), cids: ['H10.1'], signed: true);

    DB::table('recall_dirty_patients')->delete();

    rcfAdopt($this->entity, 'glaucoma'); // o evento de salvar a adoção marca a clínica

    expect(DB::table('recall_dirty_patients')->pluck('patient_id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $patient->id]);

    $stats = app(RecallDirtyDrainer::class)->drain(100);

    $condition = PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->sole();
    $recalls   = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->pluck('task_key')->sort()->values()->all();

    // A condição criada marca o paciente de novo (1 rodada a mais, sem mudança): o dreno converge, não gira.
    expect($stats['evaluated'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)
        ->and($condition->status->value)->toBe('active')
        ->and($recalls)->toBe(['campimetria', 'consulta'])
        ->and(PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $unrelated->id)->count())->toBe(0);
});
