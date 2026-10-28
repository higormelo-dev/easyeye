<?php

declare(strict_types=1);

/**
 * Evolução e procedimento do prontuário contra a assinatura, sob concorrência
 * REAL (duas conexões PostgreSQL) — achado via revisão adversarial Codex:
 * MedicalRecordEvolutionsController::store() e
 * MedicalRecordProceduresController::store() (e a execução/cancelamento do
 * procedimento) gravavam sem travar a linha do prontuário dono. Uma aba velha
 * acrescentava conteúdo clínico depois de outra aba assinar, e uma evolução
 * registrada entre o carregamento da gaveta e o clique em "Assinar" deixava o
 * token de assinatura (clinical_revision) valendo.
 *
 * Agora os dois passam por GuardedBySignedRecord: tomam a MESMA trava que
 * Signable::sign() (FOR UPDATE na linha do prontuário), recusam prontuário
 * assinado e sobem clinical_revision na mesma transação. A execução do
 * procedimento lê "origem assinada?" SOB essa trava, porque dela depende
 * sobrescrever ou acrescentar o texto do pedido.
 *
 * A "outra sessão" é uma SEGUNDA conexão PostgreSQL de verdade (a conexão
 * principal faz o papel da outra, aberta numa transação ainda sem commit), então
 * o arquivo fica em tests/Integration (DatabaseMigrations, sem transação de
 * wrap) e roda por caminho explícito — mesmo desenho de
 * MedicalRecordSignatureConcurrencyTest.
 *
 * Também a corrida entre EXECUÇÃO e CANCELAMENTO do mesmo procedimento (achado
 * via revisão adversarial Codex): duas abas carregam o procedimento como
 * "solicitado"; a que espera a trava do prontuário continuava usando a
 * instância velha e sobrescrevia o estado e o texto da que já tinha concluído.
 * Aqui o vencedor é um PROCESSO de verdade (procedure_transition_child.php)
 * segurando a trava, e o perdedor chega com a instância carregada antes.
 */

use App\Enums\{ClientRule, MedicalRecordProcedureStatus};
use App\Exceptions\{LockedMedicalRecordException, StaleMedicalRecordRevisionException};
use App\Models\{Covenant, Doctor, Entity, EntityProduct, EntityUser, MedicalRecord, MedicalRecordEvolution, MedicalRecordProcedure, Patient, People, Procedure, StockMovement, User};
use App\Services\MedicalRecordProcedureExecutionService;
use App\Services\Stock\StockService;
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Event, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

const CCC_PROBE_CONNECTION = 'pgsql_probe_clinical_children';

/** Segunda sessão PostgreSQL real: o que ela grava já está commitado. */
function cccProbe(): Connection
{
    config(['database.connections.' . CCC_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    return DB::connection(CCC_PROBE_CONNECTION);
}

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function cccChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

/**
 * @return array{record: MedicalRecord, entityUser: EntityUser, doctor: Doctor, procedure: MedicalRecordProcedure}
 */
function cccNewRecordWithProcedure(): array
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
        'entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'main_complaint' => 'Original',
    ]);
    $catalog = Procedure::query()->create(['entity_id' => $entity->id, 'code' => 'PROC-1', 'name' => 'Facectomia', 'active' => true]);

    $procedure = MedicalRecordProcedure::create([
        'entity_id'    => $entity->id, 'patient_id' => $patient->id, 'medical_record_id' => $record->id,
        'procedure_id' => $catalog->id, 'doctor_id' => $doctor->id, 'eye' => 'right', 'notes' => 'Pedido original',
    ]);

    return ['record' => $record->fresh(), 'entityUser' => $entityUser->fresh(), 'doctor' => $doctor, 'procedure' => $procedure];
}

function cccNewEvolution(MedicalRecord $record, Doctor $doctor): MedicalRecordEvolution
{
    return MedicalRecordEvolution::create([
        'entity_id' => $record->entity_id, 'patient_id' => $record->patient_id, 'medical_record_id' => $record->id,
        'doctor_id' => $doctor->id, 'content' => 'Evolução registrada durante a assinatura',
    ]);
}

beforeEach(function () {
    cccProbe();
});

afterEach(function () {
    DB::purge(CCC_PROBE_CONNECTION);
});

it('[GAP] evolução sendo gravada em OUTRA sessão (ainda sem commit) segura o sign() até comitar — e a revisão que a gaveta mostrou deixa de valer (achado via revisão adversarial Codex)', function () {
    ['record' => $record, 'entityUser' => $entityUser, 'doctor' => $doctor] = cccNewRecordWithProcedure();
    $seenRevision                                                           = $record->clinical_revision;
    $probe                                                                  = cccProbe();

    try {
        // A evolução trava a linha do prontuário, grava e sobe clinical_revision
        // na MESMA transação — ainda SEM comitar.
        DB::beginTransaction();
        cccNewEvolution($record, $doctor);

        // Quem vai assinar (conexão real separada) precisa ESPERAR a linha
        // travada. lock_timeout curto prova que bloqueou; "55P03" é o SQLSTATE
        // de "Lock not available".
        $probe->statement("SET lock_timeout = '500ms'");

        expect(fn () => MedicalRecord::on(CCC_PROBE_CONNECTION)->find($record->id)->sign($entityUser, $seenRevision))
            ->toThrow(QueryException::class, '55P03');

        // Evolução comitada: a revisão que a gaveta mostrava já não vale.
        DB::commit();
        $probe->statement('SET lock_timeout = DEFAULT');

        expect(fn () => MedicalRecord::on(CCC_PROBE_CONNECTION)->find($record->id)->sign($entityUser, $seenRevision))
            ->toThrow(StaleMedicalRecordRevisionException::class);

        $final = $record->fresh();
        expect($final->isSigned())->toBeFalse()
            ->and($final->clinical_revision)->toBe($seenRevision + 1)
            ->and(MedicalRecordEvolution::count())->toBe(1);

        // Recarregou a gaveta (revisão atual): assina normalmente.
        MedicalRecord::on(CCC_PROBE_CONNECTION)->find($record->id)->sign($entityUser, $final->clinical_revision);
        expect($record->fresh()->isSigned())->toBeTrue();
    } finally {
        $probe->statement('SET lock_timeout = DEFAULT');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

it('[GAP] execução de procedimento em OUTRA sessão (ainda sem commit) também segura o sign() até comitar — e invalida a revisão da gaveta', function () {
    ['record' => $record, 'entityUser' => $entityUser, 'procedure' => $procedure] = cccNewRecordWithProcedure();
    $seenRevision                                                                 = $record->fresh()->clinical_revision;
    $probe                                                                        = cccProbe();

    try {
        DB::beginTransaction();
        app(MedicalRecordProcedureExecutionService::class)->markDone($procedure, $entityUser, [], 'Executado.');

        $probe->statement("SET lock_timeout = '500ms'");

        expect(fn () => MedicalRecord::on(CCC_PROBE_CONNECTION)->find($record->id)->sign($entityUser, $seenRevision))
            ->toThrow(QueryException::class, '55P03');

        DB::commit();
        $probe->statement('SET lock_timeout = DEFAULT');

        expect(fn () => MedicalRecord::on(CCC_PROBE_CONNECTION)->find($record->id)->sign($entityUser, $seenRevision))
            ->toThrow(StaleMedicalRecordRevisionException::class);

        expect($record->fresh()->isSigned())->toBeFalse()
            ->and($record->fresh()->clinical_revision)->toBe($seenRevision + 1);
    } finally {
        $probe->statement('SET lock_timeout = DEFAULT');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

it('[GAP] sign() em voo (não comitado) BLOQUEIA a evolução e a solicitação de procedimento; depois do commit as duas são REJEITADAS e nada é gravado', function () {
    ['record' => $record, 'doctor' => $doctor, 'procedure' => $procedure] = cccNewRecordWithProcedure();
    $probe                                                                = cccProbe();

    $requestProcedure = fn () => MedicalRecordProcedure::create([
        'entity_id'    => $record->entity_id, 'patient_id' => $record->patient_id, 'medical_record_id' => $record->id,
        'procedure_id' => $procedure->procedure_id, 'doctor_id' => $doctor->id, 'eye' => 'left',
    ]);

    try {
        // sign() em voo: a MESMA trava de Signable::whileUnlocked() e a MESMA
        // escrita (is_locked=true), sem comitar ainda.
        $probe->beginTransaction();
        $probe->table('medical_records')->where('id', $record->id)->lockForUpdate()->first();
        $probe->table('medical_records')->where('id', $record->id)->update(['is_locked' => true]);

        DB::statement("SET lock_timeout = '500ms'");

        // Sem o lockForUpdate() do guard, estas escritas nunca tentariam travar a
        // linha do prontuário — passariam direto lendo is_locked=false e teriam
        // sucesso em vez de estourar o timeout.
        expect(fn () => cccNewEvolution($record, $doctor))->toThrow(QueryException::class, '55P03')
            ->and($requestProcedure)->toThrow(QueryException::class, '55P03');

        // O sign() comita: quem esperava (e quem chega agora) enxerga o prontuário assinado.
        $probe->commit();
        DB::statement('SET lock_timeout = DEFAULT');

        expect(fn () => cccNewEvolution($record, $doctor))->toThrow(LockedMedicalRecordException::class)
            ->and($requestProcedure)->toThrow(LockedMedicalRecordException::class);

        expect(MedicalRecordEvolution::count())->toBe(0)
            ->and(MedicalRecordProcedure::count())->toBe(1);
    } finally {
        DB::statement('SET lock_timeout = DEFAULT');

        if ($probe->transactionLevel() > 0) {
            $probe->rollBack();
        }
    }
});

it('[GAP] executar ou cancelar procedimento decide "origem assinada → acrescenta ao texto do pedido" SOB o lock: sign() que comita no meio não faz o pedido ser sobrescrito', function (string $action) {
    ['record' => $record, 'entityUser' => $entityUser, 'procedure' => $procedure] = cccNewRecordWithProcedure();

    // Entre o "begin" da transação do service e a trava da origem, OUTRA sessão
    // assina e comita. Quem leu is_locked antes da transação (ou fora dela) teria
    // visto "aberto" e sobrescrito o texto do pedido que acabou de ser assinado.
    $signed = false;
    Event::listen(TransactionBeginning::class, function () use (&$signed, $record, $entityUser): void {
        if ($signed) {
            return;
        }

        $signed = true;
        MedicalRecord::on(CCC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);
    });

    $service = app(MedicalRecordProcedureExecutionService::class);
    $result  = $action === 'markDone'
        ? $service->markDone($procedure, $entityUser, [], 'Texto novo.')
        : $service->cancel($procedure, 'Texto novo.');

    expect($signed)->toBeTrue()
        ->and($record->fresh()->isSigned())->toBeTrue()
        ->and($result->status)->toBe($action === 'markDone' ? MedicalRecordProcedureStatus::Done : MedicalRecordProcedureStatus::Cancelled)
        ->and($result->notes)->toBe("Pedido original\nTexto novo.")
        ->and($record->fresh()->verifyIntegrity())->toBeTrue();
})->with(['markDone', 'cancel']);

it('[GAP] sign() REAL em outro processo segurando a trava: a escrita ESPERA o commit e então enxerga o prontuário assinado — nunca grava por cima (achado via revisão adversarial Codex)', function (string $case) {
    ['record' => $record, 'entityUser' => $entityUser, 'doctor' => $doctor, 'procedure' => $procedure] = cccNewRecordWithProcedure();

    $marker = sys_get_temp_dir() . '/ccc-' . Str::uuid();

    // Filho: assina o prontuário e segura a trava da linha por 2,5 s.
    $child = Process::path(base_path())
        ->env(cccChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/sign_in_flight_child.php', (string) $record->id, (string) $entityUser->id, $marker, '2.5']);

    $error  = null;
    $result = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a travar o prontuário — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $startedAt = microtime(true);

        try {
            $result = match ($case) {
                'evolution' => cccNewEvolution($record, $doctor),
                'request'   => MedicalRecordProcedure::create([
                    'entity_id'    => $record->entity_id, 'patient_id' => $record->patient_id, 'medical_record_id' => $record->id,
                    'procedure_id' => $procedure->procedure_id, 'doctor_id' => $doctor->id, 'eye' => 'left',
                ]),
                'markDone' => app(MedicalRecordProcedureExecutionService::class)->markDone($procedure, $entityUser, [], 'Texto novo.'),
            };
        } catch (Throwable $e) {
            $error = $e;
        }

        $waited      = microtime(true) - $startedAt;
        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        ->and($record->fresh()->isSigned())->toBeTrue()
        // Esperou o sign() do outro processo comitar (o filho segura 2,5 s).
        ->and($waited)->toBeGreaterThan(1.0);

    if ($case === 'markDone') {
        // O pedido foi assinado enquanto a execução esperava: acrescenta, não sobrescreve.
        expect($error)->toBeNull()
            ->and($result->status)->toBe(MedicalRecordProcedureStatus::Done)
            ->and($result->notes)->toBe("Pedido original\nTexto novo.")
            ->and($record->fresh()->verifyIntegrity())->toBeTrue();

        return;
    }

    // Criar conteúdo clínico num prontuário que ACABOU de ser assinado: recusado, nada gravado.
    expect($error)->toBeInstanceOf(LockedMedicalRecordException::class)
        ->and(MedicalRecordEvolution::count())->toBe(0)
        ->and(MedicalRecordProcedure::count())->toBe(1);
})->with(['evolution', 'request', 'markDone']);

it('[GAP] corrida real entre dois processos pelo MESMO procedimento: o perdedor, com a instância carregada ANTES, espera, é rejeitado e o resultado do vencedor (estado, texto, material) fica intacto (achado via revisão adversarial Codex)', function (string $winner, string $loser, bool $signed) {
    ['record' => $record, 'entityUser' => $entityUser, 'procedure' => $procedure] = cccNewRecordWithProcedure();

    $product = EntityProduct::create(['entity_id' => $record->entity_id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 500.00);

    if ($signed) {
        MedicalRecord::on(CCC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);
    }

    // A "aba" do perdedor: carregou o procedimento como solicitado ANTES de o vencedor começar.
    $stale = MedicalRecordProcedure::query()->findOrFail($procedure->id);

    $marker = sys_get_temp_dir() . '/ccc-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(cccChildEnv())
        ->timeout(60)
        ->start([
            PHP_BINARY, 'tests/Integration/Support/procedure_transition_child.php',
            $winner, (string) $procedure->id, (string) $entityUser->id, 'Texto do vencedor.', $marker, '2.5', (string) $product->id,
        ]);

    $error = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a travar — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $startedAt = microtime(true);
        $service   = app(MedicalRecordProcedureExecutionService::class);

        try {
            if ($loser === 'cancel') {
                $service->cancel($stale, 'Texto do perdedor.');
            } else {
                $service->markDone($stale, $entityUser, [['entity_product_id' => $product->id, 'quantity' => 1.0, 'stock_lot_id' => null]], 'Texto do perdedor.');
            }
        } catch (Throwable $e) {
            $error = $e;
        }

        $waited      = microtime(true) - $startedAt;
        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    $final = MedicalRecordProcedure::query()->findOrFail($procedure->id);

    $expectedNotes = match (true) {
        $winner === 'foreign' => 'Texto do vencedor.', // UPDATE cru: o texto vai como veio
        $signed               => "Pedido original\nTexto do vencedor.", // origem assinada: acrescenta
        default               => 'Texto do vencedor.', // origem aberta: substitui
    };

    $consumptions = $winner === 'done' ? 1 : 0; // só o vencedor "done" baixa material

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // Esperou o vencedor comitar (o filho segura 2,5 s)...
        ->and($waited)->toBeGreaterThan(1.0)
        // ...e o perdedor foi REJEITADO por estado inválido, não por erro de banco.
        ->and($error)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($final->status)->toBe($winner === 'cancel' ? MedicalRecordProcedureStatus::Cancelled : MedicalRecordProcedureStatus::Done)
        ->and($final->notes)->toBe($expectedNotes)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(10.0 - $consumptions)
        ->and(StockMovement::query()->where('reference_type', MedicalRecordProcedure::class)->count())->toBe($consumptions);
})->with([
    'execução vence, cancelamento perde (origem assinada)'                => ['done', 'cancel', true],
    'execução vence, cancelamento perde (origem aberta)'                  => ['done', 'cancel', false],
    'cancelamento vence, execução com material perde (origem assinada)'   => ['cancel', 'done', true],
    'execução vence, outra execução perde — material baixado UMA vez'     => ['done', 'done', true],
    'escrita externa SEM a trava do prontuário vence, cancelamento perde' => ['foreign', 'cancel', false],
]);
