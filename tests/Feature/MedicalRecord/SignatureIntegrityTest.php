<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User};
use App\Services\MedicalRecordSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Hash de integridade da assinatura (Signable) — achado via auditoria de
 * encerramento, EE-03: o hash incluía updated_at (tocado pelo PRÓPRIO
 * save() da assinatura, depois do hash já calculado) e serializava
 * getAttributes() sem ordem canônica (ordem difere entre instância
 * recém-criada e recarregada do banco). Resultado real: assinar e
 * recarregar já deixava verifyIntegrity() falso para prontuário nenhum
 * ter sido alterado.
 */
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

function sitNewRecord(string $complaint = 'Consulta'): MedicalRecord
{
    return MedicalRecord::create([
        'entity_id'      => test()->entity->id,
        'patient_id'     => test()->patient->id,
        'doctor_id'      => test()->doctor->id,
        'main_complaint' => $complaint,
    ]);
}

it('[GAP] assinar um prontuário e recarregar depois continua íntegro — achado via auditoria de encerramento (EE-03)', function () {
    // Reproduz o cenário exato do achado: criar, carregar de novo, assinar, recarregar.
    $record = sitNewRecord()->fresh();

    app(MedicalRecordSignatureService::class)->sign($record, $this->entityUser->fresh());

    $reloaded = MedicalRecord::find($record->id);
    expect($reloaded->isSigned())->toBeTrue()
        ->and($reloaded->verifyIntegrity())->toBeTrue();
});

it('assinar imediatamente após criar, sem recarregar antes, continua íntegro (ordem de getAttributes() difere da de um reload)', function () {
    $record = sitNewRecord();

    // SEM ->fresh(): getAttributes() aqui está na ordem do INSERT (poucas
    // chaves), não na ordem completa do schema que um reload traria.
    app(MedicalRecordSignatureService::class)->sign($record, $this->entityUser->fresh());

    expect($record->fresh()->verifyIntegrity())->toBeTrue();
});

it('sign() grava o hash prefixado com a versão do algoritmo', function () {
    $record = sitNewRecord()->fresh();

    app(MedicalRecordSignatureService::class)->sign($record, $this->entityUser->fresh());

    expect($record->fresh()->signature_hash)->toStartWith('2:');
});

it('assinatura legada (hash sem prefixo de versão, fórmula antiga) continua conferindo', function () {
    $record = sitNewRecord()->fresh();

    // Fórmula ORIGINAL (pré-correção): sem excluir updated_at/updated_by, sem ksort.
    $signedAt = now()->startOfSecond();
    $content  = array_diff_key($record->getAttributes(), array_flip([
        'signed_by', 'signed_at', 'signature_hash', 'is_locked', 'clinical_revision',
        'contact_lens_calculation', 'contact_lens_use', 'medications_snapshot', 'allergies_snapshot',
    ]));
    $legacyHash = hash('sha256', implode('|', [
        $record->getKey(), $this->entityUser->id, $signedAt->toIso8601String(), hash('sha256', serialize($content)),
    ]));

    DB::table('medical_records')->where('id', $record->id)->update([
        'signed_by' => $this->entityUser->id, 'signed_at' => $signedAt, 'signature_hash' => $legacyHash, 'is_locked' => true,
    ]);

    expect($record->fresh()->signature_hash)->toBe($legacyHash)
        ->and($record->fresh()->verifyIntegrity())->toBeTrue();
});

it('adulterar conteúdo clínico após assinatura real (v2) invalida a integridade', function () {
    $record = sitNewRecord('Consulta original')->fresh();

    app(MedicalRecordSignatureService::class)->sign($record, $this->entityUser->fresh());
    expect($record->fresh()->verifyIntegrity())->toBeTrue();

    DB::table('medical_records')->where('id', $record->id)->update(['main_complaint' => 'adulterado']);

    expect($record->fresh()->verifyIntegrity())->toBeFalse();
});

it('[GAP] updated_at avançar sozinho (sem mudar conteúdo clínico) não invalida a assinatura — fica fora do hash (EE-03)', function () {
    // sign() toca updated_at no PRÓPRIO save(), depois do hash já calculado
    // — excluir é o que garante que isso nunca invalide a assinatura. Forçar
    // via SQL direto (em vez de confiar em o save() real cruzar um segundo
    // de relógio) torna a prova determinística, sem depender de timing.
    $record = sitNewRecord()->fresh();
    app(MedicalRecordSignatureService::class)->sign($record, $this->entityUser->fresh());

    DB::table('medical_records')->where('id', $record->id)->update(['updated_at' => now()->addMinutes(5)]);

    expect($record->fresh()->verifyIntegrity())->toBeTrue();
});

it('[GAP] clinical_revision (achado via revisão adversarial Codex, token de concorrência do endpoint de assinatura) nunca entra no hash — v2 assinado antes da coluna existir continua íntegro', function () {
    // Simula uma assinatura v2 de ANTES desta coluna existir: fórmula real
    // de sign()/clinicalContent(), mas sem clinical_revision no array (a
    // coluna não existia). O backfill da migration dá clinical_revision=1
    // de verdade pro registro DEPOIS — se clinicalContent() não excluísse a
    // coluna, essa assinatura antiga (legítima, nunca adulterada) passaria
    // a reprovar verifyIntegrity() sozinha, só por a coluna ter passado a
    // existir.
    $record   = sitNewRecord()->fresh();
    $signedAt = now();

    $content = array_diff_key($record->getAttributes(), array_flip([
        'signed_by', 'signed_at', 'signature_hash', 'is_locked', 'updated_at', 'updated_by', 'clinical_revision',
        'contact_lens_calculation', 'contact_lens_use', 'medications_snapshot', 'allergies_snapshot',
    ]));
    ksort($content);
    $contentHash = hash('sha256', serialize($content));
    $hash        = '2:' . hash('sha256', implode('|', [2, $record->getKey(), $this->entityUser->id, $signedAt->toIso8601String(), $contentHash]));

    DB::table('medical_records')->where('id', $record->id)->update([
        'signed_by' => $this->entityUser->id, 'signed_at' => $signedAt, 'signature_hash' => $hash, 'is_locked' => true,
    ]);

    expect($record->fresh()->clinical_revision)->toBe(1)
        ->and($record->fresh()->verifyIntegrity())->toBeTrue();
});

it('rebaixar manualmente o prefixo de versão do hash (2: para 1:) invalida a integridade', function () {
    $record = sitNewRecord()->fresh();

    app(MedicalRecordSignatureService::class)->sign($record, $this->entityUser->fresh());
    $hash = $record->fresh()->signature_hash;

    $tampered = '1:' . substr($hash, strlen('2:'));
    DB::table('medical_records')->where('id', $record->id)->update(['signature_hash' => $tampered]);

    expect($record->fresh()->verifyIntegrity())->toBeFalse();
});
