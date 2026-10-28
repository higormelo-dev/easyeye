<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User};
use App\Services\MedicalRecordSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Rollback da migration que alargou medical_records.signature_hash (de 64 para
 * 80) — achado via revisão adversarial Codex: o down() voltava a coluna para
 * varchar(64), mas o Signable grava "2:" + sha256 (66 chars). Em produção, o
 * primeiro prontuário assinado depois do deploy fazia o `migrate:rollback`
 * estourar no PostgreSQL ("value too long for type character varying(64)") e
 * deixava as migrations mais novas já revertidas — rollback pela metade. Os
 * testes de concorrência escondiam o problema: apagavam os prontuários
 * assinados antes do rollback automático.
 */
const SHM_MIGRATION = 'database/migrations/2026_10_11_010000_widen_medical_record_signature_hash_column.php';

/** O arquivo devolve a instância da migration (classe anônima). */
function shmMigration(): object
{
    return require base_path(SHM_MIGRATION);
}

function shmSignedRecord(): MedicalRecord
{
    $entity     = Entity::factory()->create(['is_client' => true]);
    $entityUser = createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value);
    $doctor     = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $record = MedicalRecord::create([
        'entity_id'      => $entity->id,
        'patient_id'     => $patient->id,
        'doctor_id'      => $doctor->id,
        'main_complaint' => 'Consulta',
    ]);

    app(MedicalRecordSignatureService::class)->sign($record->fresh(), $entityUser->fresh());

    return $record->fresh();
}

function shmSignatureColumnLength(): int
{
    $column = collect(Schema::getColumns('medical_records'))->firstWhere('name', 'signature_hash');
    preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $matches);

    return (int) ($matches[1] ?? 0);
}

it('[GAP] rollback da migration NÃO falha com prontuário assinado no formato novo (66 chars) e preserva a assinatura — achado via revisão adversarial Codex', function () {
    $record = shmSignedRecord();
    $hash   = $record->signature_hash;

    // Formato versionado de verdade: "2:" + sha256 hex = 66 caracteres.
    expect(strlen($hash))->toBe(66)->and($hash)->toStartWith('2:');

    // Era aqui que estourava (SQLSTATE 22001) no PostgreSQL: qualquer exceção
    // do down() derruba o teste.
    shmMigration()->down();

    // A coluna segue larga: a assinatura NÃO foi truncada, apagada nem reescrita.
    $record = $record->fresh();
    expect(shmSignatureColumnLength())->toBeGreaterThanOrEqual(strlen($hash))
        ->and($record->signature_hash)->toBe($hash)
        ->and($record->isSigned())->toBeTrue()
        ->and($record->verifyIntegrity())->toBeTrue();
});

it('rollback seguido de nova execução (up) é idempotente: a coluna continua cabendo o hash versionado e a assinatura intacta', function () {
    $record = shmSignedRecord();
    $hash   = $record->signature_hash;

    shmMigration()->down();
    shmMigration()->up();

    expect(shmSignatureColumnLength())->toBe(80)
        ->and($record->fresh()->signature_hash)->toBe($hash);
});
