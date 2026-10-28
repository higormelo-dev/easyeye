<?php

declare(strict_types=1);

/**
 * Numeração das versões do prontuário (record_versions) sob concorrência.
 *
 * Antes: VersionService lia max(version)+1 sem lock e o update do prontuário
 * nem sempre roda em transação. Dois saves simultâneos do mesmo prontuário
 * (duplo clique, duas abas) calculavam o MESMO número e o segundo estourava
 * record_versions_versionable_type_versionable_id_version_unique => HTTP 500 e
 * o save perdido.
 *
 * A "outra sessão" é uma SEGUNDA conexão PostgreSQL gravando (e commitando) o
 * mesmo número entre a leitura do último número e o INSERT — determinístico.
 */

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, RecordVersion, User};
use Illuminate\Database\{Connection, UniqueConstraintViolationException};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const RVC_PROBE_CONNECTION = 'pgsql_probe_record_versions';

/** Segunda sessão PostgreSQL SEM transação: o que ela grava já está commitado. */
function rvcProbe(): Connection
{
    config(['database.connections.' . RVC_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    return DB::connection(RVC_PROBE_CONNECTION);
}

/**
 * A outra sessão grava o MESMO número que esta tentativa vai usar, nas $times
 * primeiras tentativas. Devolve o estado (tentativas e ids gravados, para limpar).
 */
function rvcRivalWritesSameNumber(Connection $probe, int $times = PHP_INT_MAX): stdClass
{
    $state = (object) ['attempts' => 0, 'rivalIds' => []];

    RecordVersion::creating(function (RecordVersion $version) use ($probe, $state, $times): void {
        if (++$state->attempts > $times) {
            return;
        }

        $state->rivalIds[] = $id = (string) Str::uuid();

        // entity_id/user_id nulos: a outra sessão não enxerga as linhas ainda não
        // commitadas da transação do teste (FK).
        $probe->table('record_versions')->insert([
            'id'               => $id,
            'entity_id'        => null,
            'user_id'          => null,
            'versionable_type' => $version->versionable_type,
            'versionable_id'   => $version->versionable_id,
            'version'          => $version->version,
            'data'             => json_encode([]),
            'created_at'       => now(),
        ]);
    });

    return $state;
}

function rvcCleanup(Connection $probe, stdClass $state): void
{
    if ($state->rivalIds !== []) {
        $probe->table('record_versions')->whereIn('id', $state->rivalIds)->delete();
    }

    DB::purge(RVC_PROBE_CONNECTION);
}

function rvcVersions(MedicalRecord $record): array
{
    return RecordVersion::query()->withoutGlobalScopes()
        ->where('versionable_id', $record->id)
        ->orderBy('version')
        ->pluck('version')
        ->map(fn ($v) => (int) $v)
        ->all();
}

beforeEach(function () {
    $entity     = Entity::factory()->create(['is_client' => true]);
    $user       = User::factory()->create();
    $entityUser = createEntityUser($entity, $user, ClientRule::Doctor->value);

    $this->actingAs($user);
    session(['selected_entity_id' => $entity->id]);

    $doctor = Doctor::create([
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

    $this->record = MedicalRecord::create([
        'entity_id'      => $entity->id,
        'patient_id'     => $patient->id,
        'doctor_id'      => $doctor->id,
        'main_complaint' => 'Original',
    ]);
});

it('outra sessão grava o mesmo número entre a leitura e o INSERT: nova tentativa e o save não falha', function () {
    $probe = rvcProbe();
    $state = rvcRivalWritesSameNumber($probe, times: 1);

    try {
        $this->record->update(['main_complaint' => 'Atualizada']);

        expect($state->attempts)->toBe(2)
            ->and(rvcVersions($this->record))->toBe([1, 2])
            ->and($this->record->fresh()->main_complaint)->toBe('Atualizada');
    } finally {
        rvcCleanup($probe, $state);
    }
});

it('esgotadas as tentativas, a violação sobe (sem laço infinito) e o prontuário não muda', function () {
    $probe = rvcProbe();
    $state = rvcRivalWritesSameNumber($probe);

    try {
        expect(fn () => $this->record->update(['main_complaint' => 'Atualizada']))
            ->toThrow(UniqueConstraintViolationException::class);

        expect($state->attempts)->toBe(3)
            ->and($this->record->fresh()->main_complaint)->toBe('Original');
    } finally {
        rvcCleanup($probe, $state);
    }
});

it('outra violação única (não do número) sobe na hora, sem nova tentativa', function () {
    $this->record->update(['main_complaint' => 'Primeira']); // versão 1
    $existingId = RecordVersion::query()->withoutGlobalScopes()->where('versionable_id', $this->record->id)->value('id');
    $attempts   = 0;

    RecordVersion::creating(function (RecordVersion $version) use ($existingId, &$attempts): void {
        $attempts++;
        $version->id = $existingId; // colisão na PK, não no número
    });

    expect(fn () => $this->record->update(['main_complaint' => 'Segunda']))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($attempts)->toBe(1)
        ->and($this->record->fresh()->main_complaint)->toBe('Primeira');
});

it('versão gravada fora do escopo da clínica da sessão (job/CLI) entra na conta do próximo número', function () {
    DB::table('record_versions')->insert([
        'id'               => (string) Str::uuid(),
        'entity_id'        => null,
        'user_id'          => null,
        'versionable_type' => MedicalRecord::class,
        'versionable_id'   => $this->record->id,
        'version'          => 1,
        'data'             => json_encode([]),
        'created_at'       => now(),
    ]);

    $this->record->update(['main_complaint' => 'Atualizada']);

    expect(rvcVersions($this->record))->toBe([1, 2]);
});
