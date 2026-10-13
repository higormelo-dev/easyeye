<?php

declare(strict_types=1);

/**
 * Processamento do retorno TISS × reenvio forçado de transmissão confirmada,
 * com duas conexões PostgreSQL de verdade — achado via revisão adversarial Codex
 * (21ª rodada). O reenvio trava o lote e depois atualiza o protocolo já existente;
 * o processamento do retorno atualizava o protocolo e só depois o lote. Cada lado
 * segurando o que o outro espera = deadlock (40P01), e o PostgreSQL abortava um
 * dos dois: o reenvio podia falhar com a operadora já tendo aceitado, ou o
 * processamento do retorno tinha que ser refeito.
 *
 * Agora o processamento trava o lote ANTES de protocolo, guias e glosas (mesma
 * ordem do envio) e relê tudo depois da trava. O filho segura o lote e atualiza o
 * protocolo; o principal processa o retorno nesse intervalo.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissProtocolStatus, TissReturnStatus};
use App\Domains\Tiss\Models\{TissBatch, TissOperator, TissProtocol, TissReturn};
use App\Domains\Tiss\Services\ProcessTissReturnService;
use App\Models\Entity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function trrChildEnv(): array
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

it('[CORRIDA] reenvio forçado (lote → protocolo) e processamento do retorno ao mesmo tempo: nenhum dos dois é abortado por deadlock e o protocolo guarda a escrita dos DOIS', function () {
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-CORRIDA-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::Sent->value,
    ]);

    $protocol = TissProtocol::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_id'        => $batch->id,
        'protocol_number' => 'PRT-CORRIDA-0001',
        'status'          => TissProtocolStatus::Received->value,
        'details'         => ['source' => 'batch_send'],
    ]);

    $return = TissReturn::create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'protocol_id' => $protocol->id,
        'return_type' => 'batch_return',
        'status'      => TissReturnStatus::Parsed->value,
        'received_at' => now(),
        'summary'     => ['status' => 'accepted'],
    ]);

    $lockedMarker = sys_get_temp_dir() . '/trr-locked-' . Str::uuid();
    $goMarker     = sys_get_temp_dir() . '/trr-go-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(trrChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/tiss_resend_protocol_child.php', (string) $batch->id, (string) $protocol->id, $lockedMarker, $goMarker, '3']);

    $error = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($lockedMarker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        // Assim que o processamento do retorno ESCREVE no protocolo, dá o sinal ao filho (que então
        // vai querer o protocolo). Na ordem antiga isso acontece com o lote ainda por travar — deadlock.
        DB::listen(function (QueryExecuted $query) use ($goMarker): void {
            if (str_starts_with(strtolower($query->sql), 'update "tiss_protocols"')) {
                file_put_contents($goMarker, 'go');
            }
        });

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '30s'");

        $startedAt = microtime(true);

        try {
            app(ProcessTissReturnService::class)->process($return->fresh());
        } catch (Throwable $exception) {
            $error = $exception::class . ': ' . $exception->getMessage();
        }

        $waited      = microtime(true) - $startedAt;
        $childResult = $child->wait();
    } finally {
        @unlink($lockedMarker);
        @unlink($goMarker);
    }

    $details = $protocol->fresh()->details;

    expect($error)->toBeNull()
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // O processamento esperou o lote que o reenvio segurava (o filho espera até 3 s pelo sinal)...
        ->and($waited)->toBeGreaterThan(2.0)
        // ...releu o protocolo DEPOIS da trava (a escrita do reenvio não se perde)...
        ->and($details['resend_marker'] ?? null)->toBe('filho')
        ->and($details['return_id'] ?? null)->toBe((string) $return->id)
        ->and($details['source'] ?? null)->toBe('batch_send')
        // ...e concluiu o processamento.
        ->and($protocol->fresh()->status)->toBe(TissProtocolStatus::Accepted)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and($return->fresh()->status)->toBe(TissReturnStatus::Processed);
});
