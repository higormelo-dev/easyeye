<?php

declare(strict_types=1);

/**
 * Envio do lote TISS × geração do XML por OUTRA sessão, com duas conexões
 * PostgreSQL de verdade — achado via revisão adversarial Codex (14ª rodada):
 * send() escolhia o XML (A) e criava a transmissão em outra transação, sem
 * relê-lo; outra requisição podia gerar o B no intervalo e enviar o lote por
 * outra chave de idempotência — a operadora recebia duas mensagens.
 *
 * Agora a transmissão é reivindicada com a linha do lote travada (a trava da
 * geração do XML) e o XML escolhido é conferido contra o do lote. Aqui:
 *  (a) a outra sessão segura a trava e gera o B enquanto send() já escolheu o A:
 *      send() espera, vê o B, adota e transmite UMA mensagem (a do B);
 *  (b) o inverso: com a transmissão já reivindicada (lote "enviando", comitado),
 *      a outra sessão tenta gerar e recebe o XML que está saindo, sem trocá-lo.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito.
 */

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, SendTissBatchService, TissWorkflowService};
use App\Domains\Tiss\Services\Transport\TissTransportContract;
use App\Models\Entity;
use App\Services\Financial\BillingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function tsdrChildEnv(): array
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
 * Transporte que registra cada requisição. $whileSending roda DENTRO de
 * sendBatch() — depois da reivindicação comitada e antes do fechamento.
 */
function tsdrTransport(?Closure $whileSending = null): object
{
    $transport = new class($whileSending) implements TissTransportContract {
        /** @var list<array{key: string, xml: string}> */
        public array $requests = [];

        public function __construct(private readonly ?Closure $whileSending)
        {
        }

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->requests[] = ['key' => $idempotencyKey, 'xml' => $xml];

            if ($this->whileSending !== null) {
                ($this->whileSending)();
            }

            return [
                'ok'               => true,
                'status_code'      => 200,
                'response_body'    => '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>',
                'protocol_number'  => 'PRT-1',
                'protocol_status'  => 'accepted',
                'error_code'       => null,
                'error_message'    => null,
                'response_time_ms' => 12,
            ];
        }
    };

    app()->instance(TissTransportContract::class, $transport);

    return $transport;
}

/** Lote TISS fechado, com uma guia e o XML A (válido: CNES + CRM) já gerado — tudo comitado. */
function tsdrBatchWithDocumentA(): TissBatch
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true, 'cnes' => '1234567', 'state' => 'SP']);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);
    $schedule->doctor->update(['record' => '123456']);

    $billing = app(BillingService::class)->createBatch([
        'covenant_id'           => $schedule->covenant_id,
        'date_from'             => now()->subDay()->toDateString(),
        'date_until'            => now()->toDateString(),
        'unit_price'            => 150,
        'tuss_code'             => '10101012',
        'procedure_description' => 'CONSULTA OFTALMOLOGICA',
        'clinical_indication'   => 'H52.1',
    ]);

    $batch = app(TissWorkflowService::class)->closeBatch(TissBatch::query()->findOrFail($billing->tiss_batch_id));

    app(GenerateTissXmlService::class)->generateBatch($batch->fresh());

    return $batch->fresh();
}

it('[GAP] outra sessão segura a trava do lote e gera o XML B enquanto send() já escolheu o A: send() espera, adota o B e a operadora recebe UMA mensagem (achado via revisão adversarial Codex)', function () {
    $transport = tsdrTransport();
    $batch     = tsdrBatchWithDocumentA();
    $docA      = $batch->xmlDocument;

    $marker = sys_get_temp_dir() . '/tsdr-' . Str::uuid();
    $child  = null;

    // Logo depois de send() reservar a chave do A (escolheu, ainda não reivindicou), a
    // "outra requisição" — um processo com sessão PostgreSQL própria — trava o lote e
    // fica segurando a geração do B. O gancho só volta quando a trava já está com ela.
    DB::listen(function (QueryExecuted $query) use (&$child, $marker, $batch): void {
        if ($child !== null || ! str_starts_with(strtolower($query->sql), 'insert into "tiss_idempotency_keys"')) {
            return;
        }

        $child = Process::path(base_path())
            ->env(tsdrChildEnv())
            ->timeout(60)
            ->start([PHP_BINARY, 'tests/Integration/Support/tiss_regenerate_child.php', (string) $batch->id, $marker, '2.5', 'hold']);

        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }
    });

    try {
        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '30s'");

        $startedAt = microtime(true);

        app(SendTissBatchService::class)->send($batch->fresh());

        $waited      = microtime(true) - $startedAt;
        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    $current      = $batch->fresh();
    $transmission = TissTransmission::query()->sole();

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // Esperou a outra sessão comitar o B (o filho segura 2,5 s)...
        ->and($waited)->toBeGreaterThan(1.0)
        // ...e transmitiu o B, uma única vez.
        ->and($current->xml_document_id)->not->toBe($docA->id)
        ->and($transport->requests)->toHaveCount(1)
        ->and($transmission->xml_document_id)->toBe($current->xml_document_id)
        ->and($transport->requests[0]['xml'])->toBe(TissXmlDocument::query()->findOrFail($current->xml_document_id)->xml_content)
        ->and($current->status)->toBe(TissBatchStatus::Sent);

    // Quem tinha escolhido o B cai na transmissão que já existe: nenhuma requisição nova.
    app(SendTissBatchService::class)->send($batch->fresh());

    expect($transport->requests)->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('[GAP] com a transmissão já reivindicada (lote "enviando" comitado), a outra sessão que tenta gerar recebe o XML que está saindo — não o troca', function () {
    $batch = tsdrBatchWithDocumentA();
    $docA  = $batch->xmlDocument;

    $childOutput = null;

    // Dentro do transporte = depois da reivindicação comitada e antes do fechamento do envio.
    $transport = tsdrTransport(function () use ($batch, &$childOutput): void {
        $result = Process::path(base_path())
            ->env(tsdrChildEnv())
            ->timeout(60)
            ->run([PHP_BINARY, 'tests/Integration/Support/tiss_regenerate_child.php', (string) $batch->id, sys_get_temp_dir() . '/tsdr-unused', '0', 'plain']);

        $childOutput = [$result->successful(), trim($result->output()), $result->errorOutput()];
    });

    DB::statement("set statement_timeout = '30s'");

    app(SendTissBatchService::class)->send($batch->fresh());

    expect($childOutput)->not->toBeNull()
        ->and($childOutput[0])->toBeTrue("filho falhou — stderr: {$childOutput[2]}")
        ->and($childOutput[1])->toBe('DOC:' . $docA->id)
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->where('document_type', 'batch_submission')->count())->toBe(1)
        ->and($batch->fresh()->xml_document_id)->toBe($docA->id)
        ->and(TissTransmission::query()->sole()->xml_document_id)->toBe($docA->id)
        ->and($transport->requests)->toHaveCount(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});
