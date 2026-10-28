<?php

declare(strict_types=1);

use App\Enums\{ClientRule, MedicalRecordProcedureStatus};
use App\Exceptions\LockedMedicalRecordException;
use App\Models\{Entity, MedicalRecord, MedicalRecordEvolution, MedicalRecordProcedure, Patient, Procedure, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Evoluções e procedimentos do prontuário na fronteira da assinatura —
 * achado via revisão adversarial Codex: a gaveta do prontuário já recusava
 * assinar com revisão antiga quando um DOCUMENTO ou ANEXO mudava, mas
 * MedicalRecordEvolutionsController::store() e
 * MedicalRecordProceduresController::store() gravavam sem olhar o
 * prontuário: uma aba velha (a tela já escondia "Nova evolução"/"Solicitar"
 * com o prontuário travado, o servidor não conferia) acrescentava conteúdo
 * clínico depois da assinatura, e uma evolução/solicitação feita entre o
 * carregamento da gaveta e o clique em "Assinar" não invalidava a revisão.
 *
 * Regra: criar/alterar/excluir evolução ou procedimento passa pelo mesmo
 * guard dos documentos (GuardedBySignedRecord) — recusa prontuário assinado e
 * sobe clinical_revision sob o lock da linha do prontuário. Exceção
 * deliberada: o CICLO DE VIDA de um procedimento (solicitado → executado/
 * cancelado) continua possível depois que o prontuário de origem foi assinado
 * — a lista da tela atravessa os prontuários do paciente e executa o que foi
 * pedido num atendimento anterior —, mas como acréscimo: o texto do pedido
 * não é sobrescrito, só ganha o da execução.
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    // Médica A: abre a gaveta e assina. Médico B: outro médico da clínica.
    $this->userA = User::factory()->create();
    $this->euA   = createEntityUser($this->entity, $this->userA, ClientRule::Doctor->value);
    $this->drA   = doctorRecordForEntityUser($this->euA);

    $this->userB = User::factory()->create();
    $this->euB   = createEntityUser($this->entity, $this->userB, ClientRule::Doctor->value);
    $this->drB   = doctorRecordForEntityUser($this->euB);

    $this->catalog = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'PROC-1', 'name' => 'Facectomia', 'active' => true]);

    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id, 'main_complaint' => 'Consulta',
    ]);

    // O que a gaveta da médica A mostrou ao carregar.
    $this->seenRevision = $this->record->fresh()->clinical_revision;
});

function sepAs(object $test, $entityUser): object
{
    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

function sepSign(object $test, int $revision): object
{
    return sepAs($test, $test->euA)->post(
        route('panel.patients.medicalrecords.sign', [$test->patient, $test->record]),
        ['clinical_revision' => $revision],
    );
}

function sepStoreEvolution(object $test): object
{
    return sepAs($test, $test->euB)->postJson(
        route('panel.patients.medicalrecords.evolutions.store', [$test->patient, $test->record]),
        ['content' => 'Paciente refere melhora.'],
    );
}

function sepRequestProcedure(object $test): object
{
    return sepAs($test, $test->euB)->postJson(
        route('panel.patients.medicalrecords.medicalrecord-procedures.store', [$test->patient, $test->record]),
        ['procedure_id' => $test->catalog->id, 'eye' => 'right', 'solicitation_type' => 'rotina'],
    );
}

function sepNewProcedure(object $test, ?string $notes = null): MedicalRecordProcedure
{
    return MedicalRecordProcedure::create([
        'entity_id'    => $test->entity->id, 'patient_id' => $test->patient->id, 'medical_record_id' => $test->record->id,
        'procedure_id' => $test->catalog->id, 'doctor_id' => $test->drA->id, 'eye' => 'right', 'notes' => $notes,
    ]);
}

/** Assina com a revisão antiga e confirma que NADA foi assinado. */
function sepExpectStaleSigning(object $test): void
{
    sepSign($test, $test->seenRevision)
        ->assertRedirect()
        ->assertSessionHas('error', __('actions.medical_records.sign_stale_revision'));

    expect($test->record->fresh()->isSigned())->toBeFalse();
}

it('[GAP] evolução registrada DEPOIS de a gaveta carregar invalida a revisão — assinar com a antiga é rejeitado e nada é assinado', function () {
    sepStoreEvolution($this)->assertCreated();

    // Exatamente +1 por escrita (nem 0, nem duplicado).
    expect($this->record->fresh()->clinical_revision)->toBe($this->seenRevision + 1);

    sepExpectStaleSigning($this);

    // Recarregou a gaveta (revisão atual): assina.
    sepSign($this, $this->record->fresh()->clinical_revision)->assertSessionHas('success');
    expect($this->record->fresh()->isSigned())->toBeTrue();
});

it('[GAP] procedimento solicitado DEPOIS de a gaveta carregar invalida a revisão', function () {
    sepRequestProcedure($this)->assertCreated();

    expect($this->record->fresh()->clinical_revision)->toBe($this->seenRevision + 1);

    sepExpectStaleSigning($this);
});

it('[GAP] aba antiga registra evolução DEPOIS de a assinatura: 403 traduzido, nada gravado, revisão intacta', function () {
    sepSign($this, $this->seenRevision)->assertSessionHas('success');
    $signedRevision = $this->record->fresh()->clinical_revision;

    sepStoreEvolution($this)
        ->assertForbidden()
        ->assertJsonPath('message', __('actions.medical_records.documentation_locked'));

    expect(MedicalRecordEvolution::count())->toBe(0)
        ->and($this->record->fresh()->clinical_revision)->toBe($signedRevision);
});

it('[GAP] aba antiga solicita procedimento DEPOIS de a assinatura: 403 traduzido, nada gravado, revisão intacta', function () {
    sepSign($this, $this->seenRevision)->assertSessionHas('success');
    $signedRevision = $this->record->fresh()->clinical_revision;

    sepRequestProcedure($this)
        ->assertForbidden()
        ->assertJsonPath('message', __('actions.medical_records.documentation_locked'));

    expect(MedicalRecordProcedure::count())->toBe(0)
        ->and($this->record->fresh()->clinical_revision)->toBe($signedRevision);
});

it('[GAP] alterar ou excluir evolução/pedido de procedimento de prontuário assinado é recusado (model) — só o ciclo de vida do procedimento passa', function () {
    $evolution = MedicalRecordEvolution::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'doctor_id' => $this->drA->id, 'content' => 'Original',
    ]);
    $procedure = sepNewProcedure($this, 'Pedido original');

    sepSign($this, $this->record->fresh()->clinical_revision)->assertSessionHas('success');

    // Uma instância LIMPA por tentativa: um update() recusado deixa o atributo
    // sujo na instância e mascararia o que a próxima tentativa deveria provar.
    $evolutionNow = fn () => MedicalRecordEvolution::query()->findOrFail($evolution->id);
    $procedureNow = fn () => MedicalRecordProcedure::query()->findOrFail($procedure->id);

    expect(fn () => $evolutionNow()->update(['content' => 'Reescrita depois de assinar']))->toThrow(LockedMedicalRecordException::class)
        // Nem um save() "vazio": só o ciclo de vida do procedimento tem exceção, e é por coluna.
        ->and(fn () => $evolutionNow()->save())->toThrow(LockedMedicalRecordException::class)
        ->and(fn () => $evolutionNow()->delete())->toThrow(LockedMedicalRecordException::class)
        // Dados do PEDIDO (o que foi solicitado) ficam como assinados...
        ->and(fn () => $procedureNow()->update(['eye' => 'left']))->toThrow(LockedMedicalRecordException::class)
        // ...mesmo quando vêm junto com uma coluna do ciclo de vida.
        ->and(fn () => $procedureNow()->update(['notes' => 'Reescrito', 'eye' => 'left']))->toThrow(LockedMedicalRecordException::class)
        ->and(fn () => $procedureNow()->delete())->toThrow(LockedMedicalRecordException::class);

    expect($evolution->fresh()->content)->toBe('Original')
        ->and($procedure->fresh()->eye)->toBe('right')
        ->and($procedure->fresh()->notes)->toBe('Pedido original');
});

it('[GAP] procedimento pedido em prontuário já ASSINADO ainda pode ser executado — como acréscimo: o texto do pedido fica, a execução soma, e a revisão do assinado não muda', function () {
    $procedure = sepNewProcedure($this, 'Pedido: facectomia OD.');
    sepSign($this, $this->record->fresh()->clinical_revision)->assertSessionHas('success');
    $signedRevision = $this->record->fresh()->clinical_revision;

    // Executado por OUTRO médico, a partir de outro atendimento (a rota nem recebe o prontuário).
    sepAs($this, $this->euB)->postJson(
        route('panel.patients.medicalrecord-procedures.mark-done', [$this->patient, $procedure]),
        ['notes' => 'Executado sem intercorrências.'],
    )->assertOk();

    $procedure = $procedure->fresh();

    expect($procedure->status)->toBe(MedicalRecordProcedureStatus::Done)
        ->and($procedure->executed_at)->not->toBeNull()
        ->and($procedure->notes)->toBe("Pedido: facectomia OD.\nExecutado sem intercorrências.")
        ->and($this->record->fresh()->clinical_revision)->toBe($signedRevision)
        ->and($this->record->fresh()->verifyIntegrity())->toBeTrue();
});

it('[GAP] cancelar procedimento pedido em prontuário já ASSINADO também soma ao texto do pedido, nunca sobrescreve', function () {
    $procedure = sepNewProcedure($this, 'Pedido: YAG laser.');
    sepSign($this, $this->record->fresh()->clinical_revision)->assertSessionHas('success');

    sepAs($this, $this->euB)->postJson(
        route('panel.patients.medicalrecord-procedures.cancel', [$this->patient, $procedure]),
        ['notes' => 'Paciente desistiu.'],
    )->assertOk();

    $procedure = $procedure->fresh();

    expect($procedure->status)->toBe(MedicalRecordProcedureStatus::Cancelled)
        ->and($procedure->notes)->toBe("Pedido: YAG laser.\nPaciente desistiu.");
});

it('executar procedimento de prontuário AINDA NÃO assinado invalida a revisão dele — e o texto continua sendo substituído, como sempre foi', function () {
    $procedure          = sepNewProcedure($this, 'Pedido: facectomia OD.');
    $this->seenRevision = $this->record->fresh()->clinical_revision;

    sepAs($this, $this->euB)->postJson(
        route('panel.patients.medicalrecord-procedures.mark-done', [$this->patient, $procedure]),
        ['notes' => 'Executado.'],
    )->assertOk();

    expect($procedure->fresh()->notes)->toBe('Executado.')
        ->and($this->record->fresh()->clinical_revision)->toBe($this->seenRevision + 1);

    sepExpectStaleSigning($this);
});

it('evolução de OUTRO prontuário não invalida a revisão deste', function () {
    $other = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id, 'main_complaint' => 'Outra consulta',
    ]);

    MedicalRecordEvolution::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $other->id,
        'doctor_id' => $this->drB->id, 'content' => 'Outra evolução',
    ]);

    expect($this->record->fresh()->clinical_revision)->toBe($this->seenRevision);

    sepSign($this, $this->seenRevision)->assertSessionHas('success');
});
