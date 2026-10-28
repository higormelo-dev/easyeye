<?php

declare(strict_types=1);

/**
 * Processamento do retorno TISS × REENTREGA do job, com duas conexões
 * PostgreSQL de verdade — achado via revisão adversarial Codex (25ª rodada).
 * A fila é "pelo menos uma vez": a execução A comita o retorno e o worker morre
 * antes de confirmar; ou o job é despachado duas vezes e as duas rodam juntas. A
 * execução B tinha carregado o retorno ainda "parseado" e, sem conferir o estado
 * comitado sob a trava, processava de novo: a glosa era somada outra vez em
 * guide.denied_amount (50 virava 100 e o pago caía de 100 para 50).
 *
 * Aqui A processa numa transação que ainda NÃO comitou (segura a trava), B —
 * um processo filho — é iniciado e fica esperando a trava; só então A comita. B
 * tem que reler o retorno já `processed` e sair sem escrever nada.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus};
use App\Domains\Tiss\Models\{TissBatch, TissGlosa, TissGuide, TissOperator, TissProtocol, TissReturn, TissStatusHistory};
use App\Domains\Tiss\Services\ProcessTissReturnService;
use App\Models\Entity;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function trrdChildEnv(): array
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
 * Retorno aceito, comitado: com lote, traz uma glosa de R$ 50 numa guia de R$ 150.
 * Sem lote (`$withBatch = false`), o retorno não tem protocolo (nem glosa): não há
 * lote para travar.
 *
 * @return array{return: TissReturn, guide: TissGuide, batch: TissBatch}
 */
function trrdFixture(bool $withBatch): array
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-REENTREGA-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::Sent->value,
    ]);

    $guide = TissGuide::create([
        'entity_id'             => $entity->id,
        'operator_id'           => $operator->id,
        'guide_type'            => 'consultation',
        'guide_number_provider' => 'GUI-0001',
        'status'                => TissGuideStatus::Sent->value,
        'attendance_date'       => now()->toDateString(),
        'total_amount'          => 150,
    ]);

    DB::table('tiss_batch_guides')->insert([
        'id'          => (string) Str::uuid(),
        'entity_id'   => $entity->id,
        'batch_id'    => $batch->id,
        'guide_id'    => $guide->id,
        'attached_at' => now(),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $protocol = TissProtocol::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_id'        => $batch->id,
        'protocol_number' => 'PRT-REENTREGA-0001',
        'status'          => TissProtocolStatus::Received->value,
    ]);

    $return = TissReturn::create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'protocol_id' => $withBatch ? $protocol->id : null,
        'return_type' => 'batch_return',
        'status'      => TissReturnStatus::Parsed->value,
        'received_at' => now(),
        'summary'     => $withBatch
            ? ['status' => 'accepted', 'glosas' => [['code' => '3010', 'description' => 'Glosa teste', 'amount' => 50.0, 'guide_number_provider' => 'GUI-0001']]]
            : ['status' => 'accepted'],
    ]);

    return ['return' => $return, 'guide' => $guide, 'batch' => $batch];
}

/**
 * Espera o processo filho ficar PARADO numa trava de outra sessão. Lê pg_locks (ao
 * vivo): pg_stat_activity é "congelada" na 1ª leitura de uma transação aberta e
 * esta sessão está com a transação da execução A aberta.
 */
function trrdWaitUntilBlocked(InvokedProcess $child): bool
{
    $deadline = microtime(true) + 30;

    while (microtime(true) < $deadline && $child->running()) {
        $waiting = DB::selectOne('select count(*) as total from pg_locks where not granted and pid <> pg_backend_pid()');

        if ((int) $waiting->total > 0) {
            return true;
        }

        usleep(50_000);
    }

    return false;
}

it('[CORRIDA] reentrega concorrente do mesmo retorno: a 2ª execução espera a 1ª comitar e, relendo o retorno já processado, não soma a glosa de novo', function (bool $withBatch) {
    $f = trrdFixture($withBatch);

    // statement_timeout = rede de segurança contra pendurar a suíte.
    DB::statement("set statement_timeout = '30s'");

    // Execução A: processa e AINDA NÃO comita — segura as travas (lote e retorno).
    DB::beginTransaction();

    $child = null;

    try {
        app(ProcessTissReturnService::class)->process($f['return']->fresh());

        // Execução B: carrega o retorno ainda "parseado" (A não comitou) e tenta processá-lo.
        $child = Process::path(base_path())
            ->env(trrdChildEnv())
            ->timeout(60)
            ->start([PHP_BINARY, 'tests/Integration/Support/tiss_process_return_child.php', (string) $f['return']->id]);

        $blocked = trrdWaitUntilBlocked($child);
    } finally {
        // Comita mesmo se algo falhou: o filho não pode ficar pendurado numa trava.
        DB::commit();
    }

    $childResult = $child->wait();

    expect($blocked)->toBeTrue('o filho deveria ficar esperando a trava da execução A')
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}");

    if ($withBatch) {
        // O efeito financeiro entrou UMA vez só (antes: 50 virava 100 e o pago caía para 50)...
        expect((float) $f['guide']->fresh()->denied_amount)->toBe(50.0)
            ->and((float) $f['guide']->fresh()->paid_amount)->toBe(100.0)
            ->and($f['guide']->fresh()->status)->toBe(TissGuideStatus::PartiallyDenied)
            ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::PartiallyPaid)
            ->and(TissGlosa::query()->count())->toBe(1);
    }

    // ...e o retorno foi marcado como processado UMA vez (a execução B não reescreveu nada).
    expect($f['return']->fresh()->status)->toBe(TissReturnStatus::Processed)
        ->and(TissStatusHistory::query()->where('context_type', 'return')->where('context_id', $f['return']->id)->where('current_status', 'processed')->count())->toBe(1);
})->with([
    'retorno de um lote (trava do lote + do retorno)' => [true],
    'retorno sem lote (só a trava do retorno)'        => [false],
]);
