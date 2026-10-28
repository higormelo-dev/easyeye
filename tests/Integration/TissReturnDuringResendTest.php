<?php

declare(strict_types=1);

/**
 * Reenvio forçado × retorno da operadora processado por OUTRA sessão enquanto a
 * requisição HTTP está em voo — duas conexões PostgreSQL de verdade, achado via
 * revisão adversarial Codex (22ª rodada). O reenvio de um lote rejeitado por retorno
 * reivindica a transmissão (lote "enviando", comitado) e chama o transporte sem
 * segurar trava; nesse intervalo um retorno ACEITO é processado e comitado (lote
 * processado, guias pagas, protocolo aceito). O fechamento do reenvio — falha ou
 * sucesso — sobrescrevia esse desfecho pelo retrato que a tentativa guardou.
 *
 * Aqui o "transporte" é um duplo que, na 2ª requisição, roda um processo filho que
 * processa o retorno (e comita) antes de responder.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissGuide, TissIdempotencyKey, TissOperator, TissProtocol, TissReturn, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\SendTissBatchService;
use App\Domains\Tiss\Services\Transport\TissTransportContract;
use App\Models\Entity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function trdiChildEnv(): array
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

const TRDI_ACK_BODY = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

const TRDI_ACK_NO_STATUS = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

it('[CORRIDA] retorno aceito comitado por OUTRA sessão durante o voo do reenvio forçado: nem a falha (401) devolve o lote a "erro" nem o sucesso rebaixa lote, guias e protocolo', function (bool $resendSucceeds) {
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    TissEntityOperatorCredential::create([
        'entity_id'           => $entity->id,
        'operator_id'         => $operator->id,
        'environment'         => (string) config('tiss.transport.environment', 'production'),
        'endpoint_submission' => 'https://operadora.teste/tiss',
        'active'              => true,
    ]);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-CORRIDA-RETORNO-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::XmlReady->value,
    ]);

    $xmlContent  = '<ansTISS><mensagemTISS>teste</mensagemTISS></ansTISS>';
    $xmlDocument = TissXmlDocument::create([
        'entity_id'     => $entity->id,
        'operator_id'   => $operator->id,
        'batch_id'      => $batch->id,
        'document_type' => 'batch_submission',
        'direction'     => TissXmlDirection::Outbound->value,
        'status'        => TissXmlStatus::Validated->value,
        'file_path'     => "tiss/{$entity->id}/batches/{$batch->id}.xml",
        'file_hash'     => hash('sha256', $xmlContent),
        'xml_content'   => $xmlContent,
        'generated_at'  => now(),
        'validated_at'  => now(),
        'metadata'      => ['schema' => storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd')],
    ]);
    $batch->update(['xml_document_id' => $xmlDocument->id]);

    $guides = [];

    foreach (['GUI-0001' => 150, 'GUI-0002' => 200] as $number => $total) {
        $guide = TissGuide::create([
            'entity_id'             => $entity->id,
            'operator_id'           => $operator->id,
            'guide_type'            => 'consultation',
            'guide_number_provider' => $number,
            'status'                => TissGuideStatus::XmlGenerated->value,
            'attendance_date'       => now()->toDateString(),
            'total_amount'          => $total,
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

        $guides[] = $guide;
    }

    $returnId    = null;
    $childResult = null;

    // 1ª requisição: o aceite. 2ª (o reenvio): outra sessão processa o retorno aceito ANTES da resposta.
    $transport = new class($resendSucceeds, function () use (&$returnId, &$childResult): void {
        $childResult = Process::path(base_path())
            ->env(trdiChildEnv())
            ->timeout(60)
            ->run([PHP_BINARY, 'tests/Integration/Support/tiss_process_return_child.php', (string) $returnId]);
    }) implements TissTransportContract {
        public int $calls = 0;

        public function __construct(private readonly bool $resendSucceeds, private readonly Closure $duringResend)
        {
        }

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->calls++;

            if ($this->calls === 2) {
                ($this->duringResend)();
            }

            $success = $this->calls === 1 || $this->resendSucceeds;

            return $success
                ? ['ok' => true, 'status_code' => 200, 'response_body' => $this->calls === 1 ? TRDI_ACK_BODY : TRDI_ACK_NO_STATUS, 'protocol_number' => 'PRT-1', 'protocol_status' => $this->calls === 1 ? 'accepted' : null, 'error_code' => null, 'error_message' => null, 'response_time_ms' => 1]
                : ['ok' => false, 'status_code' => 401, 'response_body' => null, 'protocol_number' => null, 'protocol_status' => null, 'error_code' => 'http_401', 'error_message' => 'credencial recusada', 'response_time_ms' => 1];
        }
    };
    app()->instance(TissTransportContract::class, $transport);

    app(SendTissBatchService::class)->send($batch->fresh());

    // Rejeição antiga por retorno: protocolo rejeitado, lote em erro, guias negadas.
    $protocol = TissProtocol::query()->sole();
    $protocol->update(['status' => TissProtocolStatus::Rejected->value]);
    $batch->update(['status' => TissBatchStatus::Error->value, 'last_error' => 'Lote rejeitado em retorno da operadora.']);
    $batch->guides()->update(['status' => TissGuideStatus::Denied->value]);

    $return = TissReturn::create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'protocol_id' => $protocol->id,
        'return_type' => 'batch_return',
        'status'      => TissReturnStatus::Parsed->value,
        'received_at' => now(),
        'summary'     => ['status' => 'accepted'],
    ]);
    $returnId = $return->id;

    // statement_timeout = rede de segurança contra pendurar a suíte.
    DB::statement("set statement_timeout = '30s'");

    $error = null;

    try {
        app(SendTissBatchService::class)->send($batch->fresh(), force: true);
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    expect($childResult)->not->toBeNull()
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // O reenvio falho avisa que a confirmação foi preservada; o que deu certo não lança nada.
        ->and($error)->when($resendSucceeds, fn ($e) => $e->toBeNull(), fn ($e) => $e->toContain('credencial recusada'))
        // O retorno aceito da outra sessão vale: nada foi sobrescrito.
        ->and($return->fresh()->status)->toBe(TissReturnStatus::Processed)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and(collect($guides)->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Paid])
        ->and($protocol->fresh()->status)->toBe(TissProtocolStatus::Accepted)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissTransmission::query()->sole()->attempt)->toBe(2)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed')
        ->and($transport->calls)->toBe(2);
})->with([
    'o reenvio falha (401)'    => [false],
    'o reenvio dá certo (200)' => [true],
]);
