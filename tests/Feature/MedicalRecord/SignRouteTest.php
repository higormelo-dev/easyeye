<?php

declare(strict_types=1);

/**
 * Rota de assinatura eletrônica do prontuário (CFM Res. 2.227/2018) — achado
 * via auditoria de encerramento, EE-06: o serviço de assinatura e a UI que
 * já exibia o estado existiam, mas nenhuma rota chamava o serviço.
 */

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientAccount, People, User};
use App\Services\MedicalRecordService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $this->doctor     = Doctor::create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);
});

function srtAsDoctor($test)
{
    return $test->actingAs($test->user)->withSession(panelSession($test->entityUser));
}

it('[GAP] criar, editar, assinar pela rota real, compartilhar e visualizar no portal (EE-06)', function () {
    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta de rotina',
    ])->assertRedirect();

    $record = MedicalRecord::query()->where('patient_id', $this->patient->id)->sole();

    srtAsDoctor($this)->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta de rotina — revisado',
    ])->assertRedirect();

    // Documento ANTES de assinar — depois de assinado, GuardedBySignedRecord
    // trava qualquer emissão nova neste prontuário.
    $documentation = MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Prescription,
        'title'             => 'Receituário',
        'content'           => '<p>Colírio X — 1 gota 3x ao dia.</p>',
    ]);

    // O próprio achado do EE-06: assinar PELA ROTA REAL, não direto no model.
    // clinical_revision: o que a TELA mostraria pro médico agora (depois do
    // update acima) — achado via revisão adversarial Codex.
    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), [
        'clinical_revision' => $record->fresh()->clinical_revision,
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Prontuário assinado com sucesso — CFM Res. 2.227/2018.');

    $record = $record->fresh();
    expect($record->isSigned())->toBeTrue()
        ->and($record->isLocked())->toBeTrue()
        ->and($record->verifyIntegrity())->toBeTrue();

    // Compartilhar o laudo — só é aceito com o prontuário já assinado.
    srtAsDoctor($this)->postJson(route('panel.document-shares.store'), [
        'shareable_type' => 'laudo',
        'shareable_id'   => $documentation->id,
        'patient_id'     => $this->patient->id,
    ])->assertCreated();

    // Visualizar no Portal do Paciente.
    $account = PatientAccount::factory()->create(['person_id' => $this->patient->person_id]);
    loginAsPatient($account);

    $this->get(route('patient-portal.documents.view', ['laudo', $documentation->id]))
        ->assertOk();
});

it('[GAP] secretária não assina (403), nada muda', function () {
    $record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'x',
    ]);

    $secretaryUser = User::factory()->create();
    $secretaryEu   = createEntityUser($this->entity, $secretaryUser, ClientRule::Secretary->value);

    $this->actingAs($secretaryUser)->withSession(panelSession($secretaryEu))
        ->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]))
        ->assertForbidden();

    expect($record->fresh()->isSigned())->toBeFalse();
});

it('[GAP] médico de OUTRA clínica não assina (404), nada muda', function () {
    $record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'x',
    ]);

    $otherEntity = Entity::factory()->create(['is_client' => true]);
    $otherUser   = User::factory()->create();
    $otherEu     = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);

    $this->actingAs($otherUser)->withSession(panelSession($otherEu))
        ->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]))
        ->assertNotFound();

    expect($record->fresh()->isSigned())->toBeFalse();
});

it('[GAP] assinar de novo (requisição repetida) é seguro — erro traduzido, nada sobrescreve', function () {
    $record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'x',
    ]);

    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), [
        'clinical_revision' => $record->clinical_revision,
    ])->assertSessionHas('success');

    $hashAfterFirst = $record->fresh()->signature_hash;

    // clinical_revision aqui não importa pro resultado: já travado, Signable::
    // whileUnlocked() rejeita por "já assinado" antes de chegar na checagem de
    // revisão — só precisa de ALGUM inteiro válido pra passar da validação.
    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), [
        'clinical_revision' => 1,
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Este prontuário já foi assinado.');

    expect($record->fresh()->signature_hash)->toBe($hashAfterFirst);
});

it('[GAP] assinar sem clinical_revision no corpo é rejeitado (422) — achado via revisão adversarial Codex', function () {
    // Corpo vazio era exatamente o que a tela mandava antes deste fix: sem
    // isto, qualquer clique em "Assinar" travava em definitivo o que
    // estivesse no banco na hora do clique, nunca o que o médico revisou.
    $record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'x',
    ]);

    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), [])
        ->assertSessionHasErrors('clinical_revision');

    expect($record->fresh()->isSigned())->toBeFalse();
});

it('[GAP] assinar com clinical_revision DESATUALIZADO (outra edição depois que a tela carregou) é rejeitado, nada é assinado — achado via revisão adversarial Codex', function () {
    $record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Original',
    ]);

    // Médico abre a tela — revisão 1. ENQUANTO a tela ainda está aberta
    // (outra aba, ou a secretária), o conteúdo é editado — revisão vira 2.
    $staleRevision = $record->clinical_revision;
    expect($staleRevision)->toBe(1);

    app(MedicalRecordService::class)->update($record, ['main_complaint' => 'Editado por outra sessão']);
    expect($record->fresh()->clinical_revision)->toBe(2);

    // Médico clica "Assinar" na tela antiga, com a revisão 1 que ele viu.
    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), [
        'clinical_revision' => $staleRevision,
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'O prontuário, seus documentos ou anexos foram alterados por outra sessão depois que esta tela carregou. Recarregue a página e revise o conteúdo atual antes de assinar.');

    $record = $record->fresh();
    expect($record->isSigned())->toBeFalse()
        ->and($record->isLocked())->toBeFalse()
        ->and($record->main_complaint)->toBe('Editado por outra sessão');
});

it('[GAP] assinar com clinical_revision ATUAL (depois de recarregar) funciona normalmente', function () {
    $record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Original',
    ]);

    app(MedicalRecordService::class)->update($record, ['main_complaint' => 'Revisão final']);
    $currentRevision = $record->fresh()->clinical_revision;
    expect($currentRevision)->toBe(2);

    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), [
        'clinical_revision' => $currentRevision,
    ])->assertSessionHas('success');

    expect($record->fresh()->isSigned())->toBeTrue();
});

it('[GAP] clinical_revision incrementa a cada atualização do prontuário, nunca na própria assinatura', function () {
    $record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'x',
    ]);
    expect($record->clinical_revision)->toBe(1);

    app(MedicalRecordService::class)->update($record, ['main_complaint' => 'Mudança 1']);
    expect($record->fresh()->clinical_revision)->toBe(2);

    app(MedicalRecordService::class)->update($record, ['main_complaint' => 'Mudança 2']);
    expect($record->fresh()->clinical_revision)->toBe(3);

    // sign() usa saveQuietly() internamente — não é um update "normal" e não
    // deve bater no mesmo contador que a revisão de conteúdo usa.
    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), [
        'clinical_revision' => 3,
    ])->assertSessionHas('success');

    expect($record->fresh()->clinical_revision)->toBe(3);
});

/**
 * Assinatura do PDF = QUEM assinou, com nome, CRM e RQE da mesma pessoa —
 * achado via revisão adversarial Codex: a rota deixa qualquer médico emissor
 * da clínica assinar o prontuário de outro, mas o PDF imprimia o nome do
 * assinante com o CRM/RQE do médico do atendimento.
 *
 * O cabeçalho do PDF também imprime CRM/RQE (do médico do ATENDIMENTO), por
 * isso as checagens isolam o bloco de assinatura (rodapé fixo).
 */
function srtPdfHtml(MedicalRecord $record): string
{
    app()->setLocale('pt_BR');
    // A view resolve a clínica pela agenda do prontuário ou pela sessão.
    session(['selected_entity_id' => test()->entity->id]);

    return view('pdf.medical_record', ['record' => $record->fresh(), 'setting' => null])->render();
}

function srtSignatureBlock(string $html): string
{
    return Str::between($html, '<div class="pmr-signature-fixed"', '<div class="footer">');
}

/** Médica A (dona do prontuário) com credenciais próprias, e o médico B com as dele. */
function srtTwoDoctors($test): array
{
    $test->user->update(['name' => 'Dra Ana Atendimento']);
    $test->doctor->update(['record' => 'AAA111', 'record_specialty' => 'AQE222']);

    $userB       = User::factory()->create(['name' => 'Dr Bruno Assinante']);
    $entityUserB = createEntityUser($test->entity, $userB, ClientRule::Doctor->value);
    $doctorB     = Doctor::create([
        'entity_user_id'   => $entityUserB->id,
        'person_id'        => People::factory()->create()->id,
        'record'           => 'BBB999',
        'record_specialty' => 'BQE888',
        'color'            => '#00FF00',
        'partner'          => false,
        'active'           => true,
    ]);

    $record = MedicalRecord::create([
        'entity_id' => $test->entity->id, 'patient_id' => $test->patient->id, 'doctor_id' => $test->doctor->id, 'main_complaint' => 'Consulta da médica A',
    ]);

    return [$record, $userB, $entityUserB, $doctorB];
}

it('[GAP] médico B assina o prontuário da médica A: o bloco de assinatura do PDF traz nome, CRM e RQE de B — nunca o CRM/RQE de A (revisão adversarial Codex)', function () {
    [$record, $userB, $entityUserB] = srtTwoDoctors($this);

    // B assina PELA ROTA REAL — qualquer médico emissor da clínica pode.
    $this->actingAs($userB)->withSession(panelSession($entityUserB))
        ->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), ['clinical_revision' => $record->clinical_revision])
        ->assertSessionHas('success');

    expect($record->fresh()->signed_by)->toBe($entityUserB->id);

    $html      = srtPdfHtml($record);
    $signature = srtSignatureBlock($html);

    expect($signature)->toContain($userB->fresh()->name)
        ->toContain('CRM BBB999')
        ->toContain('RQE BQE888')
        // credenciais da médica A não podem aparecer sob a assinatura de B
        ->not->toContain('AAA111')
        ->not->toContain('AQE222');

    // O cabeçalho segue sendo do médico do ATENDIMENTO (A), com as credenciais dela.
    expect($html)->toContain('AAA111');
});

it('[GAP] o PDF servido pela rota imprime as credenciais de quem assinou (rota → service → view, com o SnappyPdf fake)', function () {
    SnappyPdf::fake();

    [$record, $userB, $entityUserB] = srtTwoDoctors($this);

    $this->actingAs($userB)->withSession(panelSession($entityUserB))
        ->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), ['clinical_revision' => $record->clinical_revision])
        ->assertSessionHas('success');

    $this->actingAs($userB)->withSession(panelSession($entityUserB))
        ->get(route('panel.patients.medicalrecords.pdf', [$this->patient, $record]))
        ->assertOk();

    SnappyPdf::assertViewIs('pdf.medical_record');
    SnappyPdf::assertSee('CRM BBB999');
    SnappyPdf::assertSee('RQE BQE888');
});

it('médico que assina o PRÓPRIO prontuário continua com nome, CRM e RQE dele no bloco de assinatura', function () {
    [$record] = srtTwoDoctors($this);

    srtAsDoctor($this)->post(route('panel.patients.medicalrecords.sign', [$this->patient, $record]), ['clinical_revision' => $record->clinical_revision])
        ->assertSessionHas('success');

    expect(srtSignatureBlock(srtPdfHtml($record)))->toContain($this->user->fresh()->name)
        ->toContain('CRM AAA111')
        ->toContain('RQE AQE222')
        ->not->toContain('BBB999')
        ->not->toContain('BQE888');
});

it('assinante SEM cadastro de médico: o bloco traz só o nome dele — jamais empresta o CRM/RQE do médico do atendimento', function () {
    [$record] = srtTwoDoctors($this);

    $adminUser = User::factory()->create(['name' => 'Admin Sem Cadastro']);
    $adminEu   = createEntityUser($this->entity, $adminUser, ClientRule::Admin->value);

    // Estado de um prontuário assinado por quem não tem cadastro de médico
    // (caminho de assinatura legado/dado importado) — a rota exige médico.
    $record->forceFill([
        'is_locked' => true, 'signed_at' => now(), 'signed_by' => $adminEu->id, 'signature_hash' => str_repeat('a', 64),
    ])->saveQuietly();

    $signature = srtSignatureBlock(srtPdfHtml($record));

    expect($signature)->toContain($adminUser->fresh()->name)
        ->not->toContain('CRM')
        ->not->toContain('RQE')
        ->not->toContain('AAA111')
        ->not->toContain('AQE222');
});

it('prontuário ainda NÃO assinado: o bloco mostra o médico do atendimento com o CRM/RQE dele (inalterado)', function () {
    [$record] = srtTwoDoctors($this);

    $signature = srtSignatureBlock(srtPdfHtml($record));

    expect($signature)->toContain('CRM AAA111')
        ->toContain('RQE AQE222')
        ->not->toContain('Assinado em');
});
