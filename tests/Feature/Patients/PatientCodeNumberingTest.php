<?php

declare(strict_types=1);

/**
 * Numeração PAC-NNNNNNNNNN do paciente (sequencial por clínica) sob concorrência.
 *
 * Antes: o hook `creating` lia o último código da clínica e somava 1 sem lock
 * nem nova tentativa. Dois cadastros simultâneos na mesma clínica (importação
 * em lote + cadastro na recepção, duas abas) calculavam o mesmo código e o
 * segundo estourava `patients_entity_id_code_unique` (23505) => HTTP 500 no
 * cadastro e linha da importação no CSV de erros com o SQL cru (bindings:
 * carteirinha, CPF...) e host/porta/banco da conexão.
 *
 * Cenários determinísticos: a colisão é reproduzida com uma leitura
 * "desatualizada" (listener que força o código já usado na 1ª tentativa) e a
 * contenção real com uma SEGUNDA sessão PostgreSQL segurando o lock, com
 * lock_timeout curto na sessão do teste — sem depender de timing.
 */

use App\Enums\{FeatureKey, ImportStatus, SubscriptionStatus};
use App\Models\{Covenant, Entity, Patient, PatientImport, People, Plan, PlanFeature, Subscription, User};
use App\Services\PatientImportService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\{QueryExecuted, TransactionCommitted};
use Illuminate\Database\{QueryException, UniqueConstraintViolationException};
use Illuminate\Support\Facades\{DB, Event, Storage};
use Illuminate\Support\Str;

const PCN_PROBE_CONNECTION = 'pgsql_lock_probe_patient_code';

function pcnEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function pcnCreate(Entity $entity, array $attributes = []): Patient
{
    return Patient::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ], $attributes));
}

/** Paciente gravado direto na tabela, com código fixo (histórico/legado/escritor fora do lock). */
function pcnRaw(Entity $entity, string $code, bool $trashed = false): void
{
    DB::table('patients')->insert([
        'id'          => (string) Str::uuid(),
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'code'        => $code,
        'active'      => true,
        'created_at'  => now(),
        'updated_at'  => now(),
        'deleted_at'  => $trashed ? now() : null,
    ]);
}

/**
 * Força, na 1ª tentativa de cada INSERT, um código que já existe — como se o
 * gerador tivesse lido o "último código" antes de outra sessão gravá-lo.
 * $times = quantas tentativas seguidas colidem.
 */
function pcnForceStaleCode(string $staleCode, int $times = 1): object
{
    $state = (object) ['forced' => 0];

    Patient::creating(function (Patient $patient) use ($state, $staleCode, $times): void {
        if ($state->forced < $times) {
            $state->forced++;
            $patient->code = $staleCode;
        }
    });

    return $state;
}

function pcnCodeLockKey(string $entityId): int
{
    return (new ReflectionMethod(Patient::class, 'codeNumberingLockKey'))->invoke(null, $entityId);
}

/** Segunda sessão PostgreSQL (a "outra requisição"), com transação aberta. Fechar com pcnCloseProbe(). */
function pcnProbe(): Connection
{
    config(['database.connections.' . PCN_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    $probe = DB::connection(PCN_PROBE_CONNECTION);
    $probe->beginTransaction();

    return $probe;
}

function pcnCloseProbe(Connection $probe): void
{
    if ($probe->transactionLevel() > 0) {
        $probe->rollBack();
    }

    DB::purge(PCN_PROBE_CONNECTION);
}

function pcnPatientImport(Entity $entity, string $csv): PatientImport
{
    Storage::fake();

    $path = "imports/patients/{$entity->id}/pcn.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    return PatientImport::create([
        'entity_id'     => $entity->id,
        'user_id'       => User::factory()->create()->id,
        'status'        => ImportStatus::Pending,
        'file_path'     => $path,
        'original_name' => 'pcn.csv',
    ]);
}

beforeEach(function (): void {
    $this->entity = pcnEntity();
});

describe('sequência por clínica', function (): void {
    it('criações seguidas na mesma clínica nunca repetem o código', function (): void {
        $codes = collect(range(1, 3))->map(fn () => pcnCreate($this->entity)->code);

        expect($codes->all())->toBe(['PAC-0000000001', 'PAC-0000000002', 'PAC-0000000003']);
    });

    it('é isolada por clínica', function (): void {
        $other = pcnEntity();
        pcnRaw($other, 'PAC-0000000041');

        expect(pcnCreate($this->entity)->code)->toBe('PAC-0000000001')
            ->and(pcnCreate($other)->code)->toBe('PAC-0000000042');
    });

    it('conta pacientes excluídos (soft delete): o índice único também os vê', function (): void {
        pcnRaw($this->entity, 'PAC-0000000001');
        pcnRaw($this->entity, 'PAC-0000000002', trashed: true);

        expect(pcnCreate($this->entity)->code)->toBe('PAC-0000000003');
    });

    it('ignora código fora da largura padrão (legado) que venceria na ordem textual', function (): void {
        pcnRaw($this->entity, 'PAC-0000000124');
        pcnRaw($this->entity, 'PAC-9');

        expect(pcnCreate($this->entity)->code)->toBe('PAC-0000000125');
    });

    it('mantém o código explícito (seed/legado) sem gerar outro', function (): void {
        expect(pcnCreate($this->entity, ['code' => 'PAC-0000000900'])->code)->toBe('PAC-0000000900');
    });
});

describe('colisão no índice (entity_id, code)', function (): void {
    it('leitura desatualizada vira nova tentativa com o próximo número — sem 23505 e com a transação do chamador utilizável', function (): void {
        $first = pcnCreate($this->entity);
        $stale = pcnForceStaleCode($first->code);

        $second = DB::transaction(fn () => pcnCreate($this->entity));

        expect($stale->forced)->toBe(1)
            ->and($second->code)->toBe('PAC-0000000002')
            ->and($second->exists)->toBeTrue()
            ->and(Patient::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(2);
    });

    it('esgotadas as tentativas, a violação sobe (sem laço infinito) e a transação do chamador segue utilizável', function (): void {
        $first = pcnCreate($this->entity);
        pcnForceStaleCode($first->code, times: 10);

        expect(fn () => pcnCreate($this->entity))->toThrow(UniqueConstraintViolationException::class);

        expect(Patient::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1);
    });

    it('código EXPLÍCITO que colide não é trocado em silêncio: a violação sobe', function (): void {
        pcnCreate($this->entity, ['code' => 'PAC-0000000900']);

        expect(fn () => pcnCreate($this->entity, ['code' => 'PAC-0000000900']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('[HTTP] cadastro de paciente sob colisão responde sucesso com o próximo código (antes: 500)', function (): void {
        $admin      = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $admin, 'admin');
        $covenant   = Covenant::factory()->create(['entity_id' => $this->entity->id]);
        $first      = pcnCreate($this->entity);
        $stale      = pcnForceStaleCode($first->code);

        $response = $this->actingAs($admin)
            ->withSession(panelSession($entityUser))
            ->postJson(route('panel.patients.store'), [
                'covenant_id'       => $covenant->id,
                'name'              => 'Paciente Colisao Codigo',
                'birth_date'        => '1990-01-01',
                'gender'            => 0,
                'marital_status'    => 1,
                'email'             => 'paciente.colisao@example.com',
                'national_registry' => '52998224725',
                'cellphone'         => '11999990000',
                'whatsapp'          => true,
            ]);

        $response->assertOk();

        $created = Patient::query()->withoutGlobalScopes()
            ->where('entity_id', $this->entity->id)
            ->whereKeyNot($first->id)
            ->sole();

        expect($stale->forced)->toBe(1)
            ->and($created->code)->toBe('PAC-0000000002');
    });

    it('[IMPORTAÇÃO] linha sob colisão é importada com o próximo código, não vai para o CSV de erros', function (): void {
        Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Particular']);
        $first = pcnCreate($this->entity);
        pcnForceStaleCode($first->code);

        $import = pcnPatientImport($this->entity, "nome;celular\nPaciente Importado;11977776666\n");
        app(PatientImportService::class)->process($import);

        $import->refresh();
        expect($import->imported_rows)->toBe(1)
            ->and($import->error_rows)->toBe(0)
            ->and(Patient::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->pluck('code')->sort()->values()->all())
            ->toBe(['PAC-0000000001', 'PAC-0000000002']);
    });

    it('[IMPORTAÇÃO] erro de banco numa linha grava mensagem traduzida no CSV — nunca SQL, bindings (carteirinha) ou dados da conexão', function (): void {
        Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Particular']);
        $first = pcnCreate($this->entity);
        pcnForceStaleCode($first->code, times: 10);

        $import = pcnPatientImport($this->entity, "nome;celular;carteirinha\nPaciente Com Carteira;11977776666;CARTEIRA-SECRETA-987\n");
        app(PatientImportService::class)->process($import);

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Done)
            ->and($import->error_rows)->toBe(1);

        $csv   = Storage::disk()->get($import->errors_file_path);
        $lines = array_values(array_filter(explode("\n", $csv)));
        $row   = str_getcsv($lines[1], ';');

        // A coluna _erro traz só o texto traduzido; a carteirinha aparece
        // apenas na própria coluna de dados da linha (o CSV devolve a linha).
        expect($row[1])->toBe(__('shared_identity.import.row_failed'))
            ->and($csv)->not->toContain('SQLSTATE')
            ->and($csv)->not->toContain('insert into')
            ->and($csv)->not->toContain('Connection:')
            ->and(substr_count($csv, 'CARTEIRA-SECRETA-987'))->toBe(1);
    });
});

describe('serialização da numeração', function (): void {
    it('trava a numeração da clínica ANTES de ler o último código e só solta DEPOIS do INSERT', function (): void {
        pcnCreate($this->entity); // aquece (People/Covenant fora do log)

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
        });
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$log): void {
            $log[] = ['sql' => 'commit', 'level' => $event->connection->transactionLevel()];
        });

        $person   = People::factory()->create();
        $covenant = Covenant::factory()->create();
        $log      = [];

        Patient::query()->create([
            'entity_id' => $this->entity->id, 'person_id' => $person->id, 'covenant_id' => $covenant->id, 'active' => true,
        ]);

        $entries  = collect($log);
        $lockAt   = $entries->search(fn (array $e): bool => str_contains($e['sql'], 'pg_advisory_xact_lock'));
        $lastAt   = $entries->search(fn (array $e): bool => str_contains($e['sql'], 'from "patients"') && str_contains($e['sql'], '"code"'));
        $insertAt = $entries->search(fn (array $e): bool => str_starts_with($e['sql'], 'insert into "patients"'));

        expect($lockAt)->toBeInt()
            ->and($lastAt)->toBeInt()
            ->and($insertAt)->toBeInt()
            ->and($lockAt)->toBeLessThan($lastAt)
            ->and($lastAt)->toBeLessThan($insertAt);

        $lockLevel      = $entries[$lockAt]['level'];
        $releasedBefore = $entries->slice($lockAt + 1, $insertAt - $lockAt - 1)
            ->contains(fn (array $e): bool => $e['sql'] === 'commit' && $e['level'] < $lockLevel);

        expect($releasedBefore)->toBeFalse();
    });

    it('outra sessão numerando a MESMA clínica bloqueia a criação até soltar o lock', function (): void {
        $probe = pcnProbe();

        try {
            $probe->select('select pg_advisory_xact_lock(?)', [pcnCodeLockKey((string) $this->entity->id)]);
            DB::statement("set local lock_timeout = '300ms'");

            expect(fn () => pcnCreate($this->entity))
                ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
        } finally {
            pcnCloseProbe($probe);
        }

        // A tentativa que esperou não gravou nada, e a transação do chamador segue utilizável.
        expect(Patient::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(0)
            ->and(pcnCreate($this->entity)->code)->toBe('PAC-0000000001');
    });

    it('o lock de uma clínica não bloqueia a numeração de outra', function (): void {
        $other = pcnEntity();
        $probe = pcnProbe();

        try {
            $probe->select('select pg_advisory_xact_lock(?)', [pcnCodeLockKey((string) $this->entity->id)]);
            DB::statement("set local lock_timeout = '300ms'");

            expect(pcnCreate($other)->code)->toBe('PAC-0000000001');
        } finally {
            pcnCloseProbe($probe);
        }
    });

    it('o lock continua com quem criou até o fim da transação do chamador', function (): void {
        $probe    = pcnProbe();
        $acquired = null;

        Patient::created(function () use ($probe, &$acquired): void {
            $acquired ??= (bool) $probe->selectOne('select pg_try_advisory_xact_lock(?) as ok', [pcnCodeLockKey((string) $this->entity->id)])->ok;
        });

        try {
            pcnCreate($this->entity);
        } finally {
            pcnCloseProbe($probe);
        }

        expect($acquired)->toBeFalse();
    });
});

/** Violação única como o driver entrega (errorInfo[2] = mensagem do servidor, sem SQL). */
function pcnUniqueViolation(string $driver, string $driverMessage, array $bindings = []): UniqueConstraintViolationException
{
    $pdo            = new PDOException('SQLSTATE[23505]: Unique violation: 7 ' . $driverMessage);
    $pdo->errorInfo = [$driver === 'sqlite' ? '23000' : '23505', 7, $driverMessage];

    return new UniqueConstraintViolationException($driver, 'insert into "patients" ("notes") values (?)', $bindings, $pdo);
}

describe('detecção da colisão de código', function (): void {
    it('decide pela mensagem do DRIVER: índice citado só nos bindings (texto digitado) não vira nova tentativa', function (): void {
        $isCodeCollision = fn (UniqueConstraintViolationException $e): bool => (new ReflectionMethod(Patient::class, 'isCodeCollision'))->invoke(null, $e);

        $code       = pcnUniqueViolation('pgsql', 'ERRO:  duplicar valor da chave viola a restrição de unicidade "patients_entity_id_code_unique"');
        $importCode = pcnUniqueViolation(
            'pgsql',
            'ERRO:  duplicar valor da chave viola a restrição de unicidade "patients_entity_id_import_code_unique"',
            ['observação citando patients_entity_id_code_unique e patients.entity_id, patients.code'],
        );

        expect($importCode->getMessage())->toContain('patients_entity_id_code_unique')
            ->and($isCodeCollision($code))->toBeTrue()
            ->and($isCodeCollision($importCode))->toBeFalse()
            // SQLite não cita o índice, só as colunas — lista exata.
            ->and($isCodeCollision(pcnUniqueViolation('sqlite', 'UNIQUE constraint failed: patients.entity_id, patients.code')))->toBeTrue()
            ->and($isCodeCollision(pcnUniqueViolation('sqlite', 'UNIQUE constraint failed: patients.entity_id, patients.import_code')))->toBeFalse();
    });
});
