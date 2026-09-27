<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Doctor, Entity, MedicalRecord, Patient, People, User};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\{QueryExecuted, TransactionCommitted};
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;

/**
 * Numeração PMR-NNNNNNNNNN do prontuário (sequencial por clínica).
 *
 * Antes: o hook `creating` travava (FOR UPDATE) a última linha dentro de uma
 * transação que terminava ANTES do INSERT — dois prontuários simultâneos da
 * mesma clínica recebiam o mesmo código, em silêncio (não há índice único).
 * No primeiro prontuário da clínica nem havia linha para travar.
 *
 * Aqui: cenários determinísticos. A contenção real é provada com uma
 * SEGUNDA sessão PostgreSQL (outra conexão PDO) segurando o lock, com
 * lock_timeout curto na sessão do teste — sem depender de timing.
 */
const MRCN_PROBE_CONNECTION = 'pgsql_lock_probe_numbering';

function mrcnDoctor(Entity $entity): Doctor
{
    return Doctor::query()->create([
        'entity_user_id' => createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value)->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);
}

function mrcnCreate(Entity $entity, Patient $patient, Doctor $doctor): MedicalRecord
{
    return MedicalRecord::query()->create([
        'entity_id'  => $entity->id,
        'patient_id' => $patient->id,
        'doctor_id'  => $doctor->id,
    ]);
}

/** Prontuário gravado direto na tabela, com código fixo (histórico/legado). */
function mrcnRaw(Entity $entity, Patient $patient, Doctor $doctor, string $code, bool $trashed = false): void
{
    DB::table('medical_records')->insert([
        'id'         => (string) Str::uuid(),
        'entity_id'  => $entity->id,
        'patient_id' => $patient->id,
        'doctor_id'  => $doctor->id,
        'code'       => $code,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => $trashed ? now() : null,
    ]);
}

function mrcnCodeLockKey(?string $entityId): int
{
    return (new ReflectionMethod(MedicalRecord::class, 'codeNumberingLockKey'))->invoke(null, $entityId);
}

/**
 * Abre a segunda sessão PostgreSQL (a "outra requisição"), com transação
 * aberta. O chamador SEMPRE fecha com mrcnCloseProbe() (finally).
 */
function mrcnProbe(): Connection
{
    config(['database.connections.' . MRCN_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    $probe = DB::connection(MRCN_PROBE_CONNECTION);
    $probe->beginTransaction();

    return $probe;
}

function mrcnCloseProbe(Connection $probe): void
{
    if ($probe->transactionLevel() > 0) {
        $probe->rollBack();
    }

    DB::purge(MRCN_PROBE_CONNECTION);
}

beforeEach(function (): void {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor  = mrcnDoctor($this->entity);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
});

describe('sequência por clínica', function (): void {
    it('duas criações seguidas na mesma clínica nunca repetem o código', function (): void {
        $codes = collect(range(1, 3))->map(fn () => mrcnCreate($this->entity, $this->patient, $this->doctor)->code);

        expect($codes->all())->toBe(['PMR-0000000001', 'PMR-0000000002', 'PMR-0000000003']);
    });

    it('é isolada por clínica', function (): void {
        $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherDoctor  = mrcnDoctor($other);
        $otherPatient = Patient::factory()->create(['entity_id' => $other->id]);
        mrcnRaw($other, $otherPatient, $otherDoctor, 'PMR-0000000041');

        expect(mrcnCreate($this->entity, $this->patient, $this->doctor)->code)->toBe('PMR-0000000001')
            ->and(mrcnCreate($other, $otherPatient, $otherDoctor)->code)->toBe('PMR-0000000042');
    });

    it('conta prontuários excluídos (soft delete): código nunca é reaproveitado', function (): void {
        mrcnRaw($this->entity, $this->patient, $this->doctor, 'PMR-0000000001');
        mrcnRaw($this->entity, $this->patient, $this->doctor, 'PMR-0000000002', trashed: true);

        expect(mrcnCreate($this->entity, $this->patient, $this->doctor)->code)->toBe('PMR-0000000003');
    });

    it('ignora código fora da largura padrão (antes "PMR-123" vencia na ordem textual e o próximo repetia um código existente)', function (): void {
        mrcnRaw($this->entity, $this->patient, $this->doctor, 'PMR-0000000124');
        mrcnRaw($this->entity, $this->patient, $this->doctor, 'PMR-0000000125');
        mrcnRaw($this->entity, $this->patient, $this->doctor, 'PMR-123');

        expect(mrcnCreate($this->entity, $this->patient, $this->doctor)->code)->toBe('PMR-0000000126');
    });

    it('mantém o código explícito (fixtures/legado) sem gerar outro', function (): void {
        $record = MedicalRecord::query()->create([
            'entity_id'  => $this->entity->id,
            'patient_id' => $this->patient->id,
            'doctor_id'  => $this->doctor->id,
            'code'       => 'PMR-0000000900',
        ]);

        expect($record->code)->toBe('PMR-0000000900');
    });
});

describe('serialização da numeração', function (): void {
    it('trava a numeração da clínica ANTES de ler o último código e só solta DEPOIS do INSERT', function (): void {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
        });
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$log): void {
            $log[] = ['sql' => 'commit', 'level' => $event->connection->transactionLevel()];
        });

        mrcnCreate($this->entity, $this->patient, $this->doctor);

        $entries  = collect($log);
        $lockAt   = $entries->search(fn (array $e): bool => str_contains($e['sql'], 'pg_advisory_xact_lock'));
        $lastAt   = $entries->search(fn (array $e): bool => str_contains($e['sql'], 'from "medical_records"') && str_contains($e['sql'], '"code"'));
        $insertAt = $entries->search(fn (array $e): bool => str_starts_with($e['sql'], 'insert into "medical_records"'));

        expect($lockAt)->toBeInt()
            ->and($lastAt)->toBeInt()
            ->and($insertAt)->toBeInt()
            ->and($lockAt)->toBeLessThan($lastAt)
            ->and($lastAt)->toBeLessThan($insertAt);

        // Nenhum commit entre o lock e o INSERT baixa o nível de transação
        // abaixo do nível em que o lock foi pego (antes: o lock era solto ali).
        $lockLevel      = $entries[$lockAt]['level'];
        $releasedBefore = $entries->slice($lockAt + 1, $insertAt - $lockAt - 1)
            ->contains(fn (array $e): bool => $e['sql'] === 'commit' && $e['level'] < $lockLevel);

        expect($releasedBefore)->toBeFalse()
            ->and($entries[$insertAt]['level'])->toBeGreaterThanOrEqual($lockLevel);
    });

    it('outra sessão numerando a MESMA clínica bloqueia a criação até soltar o lock', function (): void {
        $probe = mrcnProbe();

        try {
            $probe->select('select pg_advisory_xact_lock(?)', [mrcnCodeLockKey((string) $this->entity->id)]);
            DB::statement("set local lock_timeout = '300ms'");

            expect(fn () => mrcnCreate($this->entity, $this->patient, $this->doctor))
                ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
        } finally {
            mrcnCloseProbe($probe);
        }

        // A tentativa que esperou não gravou nada, e a transação do chamador segue utilizável.
        expect(MedicalRecord::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(0)
            ->and(mrcnCreate($this->entity, $this->patient, $this->doctor)->code)->toBe('PMR-0000000001');
    });

    it('o lock de uma clínica não bloqueia a numeração de outra', function (): void {
        $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherDoctor  = mrcnDoctor($other);
        $otherPatient = Patient::factory()->create(['entity_id' => $other->id]);
        $probe        = mrcnProbe();

        try {
            $probe->select('select pg_advisory_xact_lock(?)', [mrcnCodeLockKey((string) $this->entity->id)]);
            DB::statement("set local lock_timeout = '300ms'");

            expect(mrcnCreate($other, $otherPatient, $otherDoctor)->code)->toBe('PMR-0000000001');
        } finally {
            mrcnCloseProbe($probe);
        }
    });

    it('o lock continua com quem criou até o fim da transação (a outra sessão não consegue pegá-lo logo após o INSERT)', function (): void {
        $probe    = mrcnProbe();
        $acquired = null;

        MedicalRecord::created(function () use ($probe, &$acquired): void {
            $acquired ??= (bool) $probe->selectOne('select pg_try_advisory_xact_lock(?) as ok', [mrcnCodeLockKey((string) $this->entity->id)])->ok;
        });

        try {
            mrcnCreate($this->entity, $this->patient, $this->doctor);
        } finally {
            mrcnCloseProbe($probe);
        }

        expect($acquired)->toBeFalse();
    });
});
