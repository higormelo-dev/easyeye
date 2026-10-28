<?php

declare(strict_types=1);

/**
 * Atualização do sistema × XML gerado ANTES da guarda de schema (EE-07) —
 * achado via revisão adversarial Codex (24ª rodada).
 *
 * O validador antigo procurava um "ansTISS.xsd" que nunca existiu: todo XML
 * nascia "validado" com metadata.schema = null. A guarda do transporte real
 * (SendTissBatchSchemaGuardTest) passou a exigir o schema ANTES de decidir o
 * que fazer com a transmissão — e lote enviando/enviado não regera o XML
 * (GenerateTissXmlService devolve o que saiu), então a entrega em dúvida de um
 * XML antigo ficava travada para sempre e o "tentar de novo" de um envio já
 * concluído também falhava.
 *
 * Agora a validação só é exigida quando uma requisição VAI sair (primeiro
 * envio, retomada, reenvio forçado) e, para o XML antigo, é feita no lugar: o
 * MESMO xml_content é validado contra o XSD resolvido e a evidência é gravada
 * no próprio documento — bytes, hash, id e chave de idempotência intactos.
 * XML que o XSD rejeita continua barrado. O que devolve uma transmissão sem
 * transmitir (já confirmada, lote já entregue) nem chega a validar.
 *
 * Aqui o transporte é o HttpTissTransportClient de verdade (a guarda só vale
 * para ele) com Http::fake, e o XML é o gerado pelo builder real, válido
 * contra o XSD real da versão 202603. O "legado" é fabricado tirando a
 * evidência de um documento que já foi (ou tentou ser) enviado.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissTransmissionStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, SendTissBatchService, TissWorkflowService, ValidateTissXmlService};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissTransportContract};
use App\Models\Entity;
use App\Services\Financial\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const LXU_ACK_BODY = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

const LXU_STALE_WARNING = 'Validação XSD ignorada por ausência de schema.';

/**
 * Lote TISS fechado, com a credencial da operadora e o XML REAL já gerado e
 * validado (CNES + CRM preenchidos: sem eles o XSD rejeita o XML), pronto
 * para o transporte HTTP.
 */
function lxuBatch(): TissBatch
{
    Carbon::setTestNow('2026-10-12 12:00:00');

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

    // O fechamento do lote já cria a credencial da operadora (sem endpoint).
    TissEntityOperatorCredential::query()->updateOrCreate(
        [
            'entity_id'   => $entity->id,
            'operator_id' => $batch->operator_id,
            'environment' => (string) config('tiss.transport.environment', 'production'),
        ],
        ['endpoint_submission' => 'https://operadora.teste/tiss', 'active' => true],
    );

    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);

    app(GenerateTissXmlService::class)->generateBatch($batch->fresh());

    $batch = $batch->fresh(['xmlDocument']);

    // Pré-condição do cenário inteiro: o XML do builder é válido contra o XSD
    // e nasceu com a evidência — o "legado" é fabricado a partir dele.
    expect($batch->xmlDocument->status->value)->toBe('validated')
        ->and($batch->xmlDocument->metadata['schema'] ?? null)->not->toBeNull();

    return $batch;
}

/**
 * Transporte HTTP com roteiro (uma resposta por requisição; 'ack' quando o
 * roteiro acaba): 'lost' = resposta perdida (exceção de transporte, sem status
 * HTTP — a operadora PODE ter recebido), 'ack' = aceite.
 *
 * A requisição que se perde não entra em Http::recorded() (o stub lança antes
 * de o par ser gravado), então o roteiro anota TODA requisição que chegou ao
 * transporte: chave de idempotência, corpo e a evidência que o documento tinha
 * no banco NAQUELE instante (prova que a validação foi gravada antes da entrega).
 *
 * @param list<string> $steps
 *
 * @return ArrayObject<string, mixed> requests: list<array{key: string, body: string, metadata: array|null}>
 */
function lxuTransport(string $documentId, array $steps = []): ArrayObject
{
    $state = new ArrayObject(['requests' => [], 'steps' => $steps]);

    Http::fake(['*' => function ($request) use ($state, $documentId) {
        $state['requests'] = [...$state['requests'], [
            'key'      => (string) ($request->header('X-Idempotency-Key')[0] ?? ''),
            'body'     => $request->body(),
            'metadata' => TissXmlDocument::query()->whereKey($documentId)->value('metadata'),
        ]];

        $steps          = $state['steps'];
        $step           = array_shift($steps) ?? 'ack';
        $state['steps'] = $steps;

        if ($step === 'lost') {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
        }

        return Http::response(LXU_ACK_BODY, 200);
    }]);

    return $state;
}

/**
 * O documento como o validador ANTIGO o deixava: "validado", sem schema, com o
 * aviso de que a validação foi ignorada — e um campo a mais no metadata, que a
 * revalidação não pode apagar.
 */
function lxuMakeLegacy(TissBatch $batch): TissXmlDocument
{
    $document = $batch->fresh()->xmlDocument;

    $document->update([
        'validated_at'      => now()->subYear(),
        'validation_errors' => [LXU_STALE_WARNING],
        'metadata'          => ['schema' => null, 'custom' => 'preservado'],
    ]);

    return $document->fresh();
}

/** Envio (sem lançar) que se perde: transmissão em timeout, lote enviando, chave em dúvida. */
function lxuLostDelivery(TissBatch $batch): TissTransmission
{
    app(SendTissBatchService::class)->send($batch->fresh(), throwOnError: false);

    $transmission = TissTransmission::query()->sole();

    expect($transmission->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain');

    return $transmission;
}

/** Envio confirmado: transmissão aceita, lote enviado, chave concluída. */
function lxuConfirmedDelivery(TissBatch $batch): TissTransmission
{
    $transmission = app(SendTissBatchService::class)->send($batch->fresh());

    expect($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');

    return $transmission;
}

/** O documento segue como estava: sem evidência nova, sem mexer no que o legado tinha. */
function lxuExpectDocumentUntouched(TissXmlDocument $before): void
{
    $after = $before->fresh();

    expect($after->xml_content)->toBe($before->xml_content)
        ->and($after->file_hash)->toBe($before->file_hash)
        ->and($after->metadata)->toBe(['schema' => null, 'custom' => 'preservado'])
        ->and($after->validation_errors)->toBe([LXU_STALE_WARNING])
        ->and($after->validated_at->equalTo($before->validated_at))->toBeTrue();
}

// ─────────────────────────────────────────────────────────────────────────────
// Entrega em dúvida de XML legado: retoma no lugar
// ─────────────────────────────────────────────────────────────────────────────

it('[UPGRADE] entrega em dúvida de XML legado (schema=null) retoma com o MESMO XML e a MESMA chave — a validação é gravada no próprio documento antes de reenviar', function () {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    $state    = lxuTransport($document->id, ['lost']);

    $lost   = lxuLostDelivery($batch);
    $legacy = lxuMakeLegacy($batch);

    $this->travel(30)->seconds();

    $resumed = app(SendTissBatchService::class)->send($batch->fresh());

    // Mesma transmissão, mesma chave, segunda tentativa, aceita.
    expect($resumed->is($lost))->toBeTrue()
        ->and($resumed->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($resumed->attempt)->toBe(2)
        ->and($resumed->idempotency_key)->toBe($lost->idempotency_key)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');

    // A operadora recebeu DUAS vezes o mesmo corpo com a mesma chave — é o que
    // permite a ela deduplicar a 1ª, se chegou a processá-la.
    expect($state['requests'])->toHaveCount(2)
        ->and($state['requests'][1]['key'])->toBe($state['requests'][0]['key'])
        ->and($state['requests'][1]['key'])->toBe($lost->idempotency_key)
        ->and($state['requests'][1]['body'])->toBe($state['requests'][0]['body'])
        ->and($state['requests'][1]['body'])->toBe($legacy->xml_content);

    // Identidade do documento intacta: nada foi regerado.
    $after = $document->fresh();
    expect(TissXmlDocument::query()->where('batch_id', $batch->id)->where('document_type', 'batch_submission')->count())->toBe(1)
        ->and($batch->fresh()->xml_document_id)->toBe($document->id)
        ->and($after->xml_content)->toBe($legacy->xml_content)
        ->and($after->file_hash)->toBe($legacy->file_hash)
        ->and($after->file_path)->toBe($legacy->file_path);

    // Evidência gravada NO documento — e já visível quando a requisição saiu.
    expect($state['requests'][1]['metadata']['schema'] ?? null)->toEndWith('tissV4_03_00.xsd')
        ->and($after->metadata['schema'])->toEndWith('tissV4_03_00.xsd')
        ->and($after->metadata['revalidated_at'])->toBe(now()->toIso8601String())
        ->and($after->metadata['custom'])->toBe('preservado')
        ->and($after->validation_errors)->toBe([])
        ->and($after->validated_at->equalTo(now()))->toBeTrue();
});

it('[UPGRADE] com a evidência já gravada, a retomada seguinte não valida de novo', function () {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    $state    = lxuTransport($document->id, ['lost', 'lost']);

    lxuLostDelivery($batch);
    lxuMakeLegacy($batch);

    // 2ª tentativa também se perde: a evidência foi gravada mesmo assim.
    app(SendTissBatchService::class)->send($batch->fresh(), throwOnError: false);

    $revalidatedAt = $document->fresh()->metadata['revalidated_at'] ?? null;
    expect($revalidatedAt)->not->toBeNull();

    // 3ª tentativa: nenhum novo cálculo — o validador nem é chamado.
    test()->mock(ValidateTissXmlService::class)->shouldNotReceive('validate');
    $this->travel(30)->seconds();

    app(SendTissBatchService::class)->send($batch->fresh());

    expect($document->fresh()->metadata['revalidated_at'])->toBe($revalidatedAt)
        ->and($state['requests'])->toHaveCount(3)
        ->and(TissTransmission::query()->sole()->attempt)->toBe(3);
});

it('[UPGRADE] XML legado que nunca foi enviado também é revalidado no lugar: sai o mesmo documento, sem regerar', function () {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    $state    = lxuTransport($document->id);

    $legacy = lxuMakeLegacy($batch);

    $transmission = app(SendTissBatchService::class)->send($batch->fresh());

    expect($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->where('document_type', 'batch_submission')->count())->toBe(1)
        ->and($batch->fresh()->xml_document_id)->toBe($document->id)
        ->and($state['requests'])->toHaveCount(1)
        ->and($state['requests'][0]['body'])->toBe($legacy->xml_content)
        ->and($state['requests'][0]['metadata']['schema'] ?? null)->toEndWith('tissV4_03_00.xsd')
        ->and($transmission->idempotency_key)->toBe(hash('sha256', implode('|', [$batch->entity_id, $batch->id, $legacy->file_hash])));
});

// ─────────────────────────────────────────────────────────────────────────────
// Envio já concluído de XML legado: devolve sem transmitir e sem validar
// ─────────────────────────────────────────────────────────────────────────────

it('[UPGRADE] transmissão já concluída de XML legado volta como está — nenhuma requisição nova, nenhuma validação', function (string $situation) {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;

    if ($situation === 'lote entregue por retorno, transmissão em dúvida') {
        $state        = lxuTransport($document->id, ['lost']);
        $transmission = lxuLostDelivery($batch);
        // A operadora processou o retorno do lote: ele está entregue, mesmo com a resposta do envio perdida.
        $batch->fresh()->update(['status' => TissBatchStatus::Processed->value]);
    } else {
        $state        = lxuTransport($document->id);
        $transmission = lxuConfirmedDelivery($batch);

        if ($situation === 'confirmada, chave sem a marca de concluída') {
            // Dado anterior à marca: só a transmissão (e o lote) dizem que foi entregue.
            TissIdempotencyKey::query()->sole()->update(['status' => 'reserved']);
        }
    }

    $legacy = lxuMakeLegacy($batch);

    // O validador não pode ser chamado: nada vai sair.
    test()->mock(ValidateTissXmlService::class)->shouldNotReceive('validate');

    $this->travel(30)->seconds();

    $returned = app(SendTissBatchService::class)->send($batch->fresh());

    expect($returned->is($transmission))->toBeTrue()
        ->and($returned->idempotency_key)->toBe($transmission->idempotency_key)
        ->and($returned->status)->toBe($transmission->status)
        ->and($state['requests'])->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->where('document_type', 'batch_submission')->count())->toBe(1);

    lxuExpectDocumentUntouched($legacy);

    expect($batch->fresh()->status)->toBe(
        $situation === 'lote entregue por retorno, transmissão em dúvida' ? TissBatchStatus::Processed : TissBatchStatus::Sent,
    );
})->with([
    'confirmada e concluída'                           => ['confirmada e concluída'],
    'confirmada, chave sem a marca de concluída'       => ['confirmada, chave sem a marca de concluída'],
    'lote entregue por retorno, transmissão em dúvida' => ['lote entregue por retorno, transmissão em dúvida'],
]);

it('[UPGRADE] reenvio FORÇADO de transmissão concluída com XML legado revalida no lugar e reenvia o MESMO XML com a MESMA chave — a confirmação fica preservada', function () {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    $state    = lxuTransport($document->id);

    $confirmed = lxuConfirmedDelivery($batch);
    $legacy    = lxuMakeLegacy($batch);

    $this->travel(30)->seconds();

    $resent = app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    expect($resent->is($confirmed))->toBeTrue()
        ->and($resent->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($resent->idempotency_key)->toBe($confirmed->idempotency_key)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and($state['requests'])->toHaveCount(2)
        ->and($state['requests'][1]['key'])->toBe($state['requests'][0]['key'])
        ->and($state['requests'][1]['body'])->toBe($legacy->xml_content)
        ->and($state['requests'][1]['metadata']['schema'] ?? null)->toEndWith('tissV4_03_00.xsd')
        ->and($document->fresh()->file_hash)->toBe($legacy->file_hash);
});

// ─────────────────────────────────────────────────────────────────────────────
// O que continua barrado
// ─────────────────────────────────────────────────────────────────────────────

it('[UPGRADE] XML legado que o XSD rejeita continua barrado: nada sai, o documento e a transmissão em dúvida ficam como estavam', function (string $content, string $situation) {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    $state    = lxuTransport($document->id, $situation === 'dúvida' ? ['lost'] : []);

    $transmission = $situation === 'dúvida' ? lxuLostDelivery($batch) : lxuConfirmedDelivery($batch);
    $batchStatus  = $batch->fresh()->status;
    $keyStatus    = TissIdempotencyKey::query()->sole()->status;

    // Documento legado cujo conteúdo o XSD não aceita (bem formado ou nem isso).
    $document->update(['xml_content' => $content]);
    $legacy = lxuMakeLegacy($batch);

    $this->travel(30)->seconds();

    try {
        app(SendTissBatchService::class)->send($batch->fresh(), force: $situation !== 'dúvida');
        $this->fail('send() deveria barrar o XML que o XSD rejeita.');
    } catch (RuntimeException $e) {
        // A mensagem diz o que o XSD apontou, como na regeneração.
        expect($e->getMessage())->toContain('rejeitado na validação')->toContain('Erros:');
    }

    expect($state['requests'])->toHaveCount(1)
        ->and($transmission->fresh()->status)->toBe($transmission->status)
        ->and($transmission->fresh()->attempt)->toBe(1)
        ->and($batch->fresh()->status)->toBe($batchStatus)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe($keyStatus)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->where('document_type', 'batch_submission')->count())->toBe(1);

    lxuExpectDocumentUntouched($legacy);
})->with([
    'rejeitado pelo XSD, em dúvida'                    => ['<ansTISS><mensagemTISS>incompleto</mensagemTISS></ansTISS>', 'dúvida'],
    'malformado, em dúvida'                            => ['<ansTISS><mensagemTISS>', 'dúvida'],
    'rejeitado pelo XSD, reenvio forçado de concluída' => ['<ansTISS><mensagemTISS>incompleto</mensagemTISS></ansTISS>', 'concluída'],
]);

it('[UPGRADE] a guarda que rejeita não escreve no documento — sem depender do rollback da transação da reivindicação', function (string $content) {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    lxuTransport($document->id);

    $document->update(['xml_content' => $content]);
    $legacy = lxuMakeLegacy($batch);

    // Direto, fora da reivindicação: dentro dela o rollback desfaria uma escrita
    // feita antes de a validade ser conferida, e o defeito passaria despercebido
    // por send(). A rejeição não pode deixar rastro no documento nem por um instante.
    $service = app(SendTissBatchService::class);
    $guard   = new ReflectionMethod($service, 'assertDeliverable');

    expect(fn () => $guard->invoke($service, $batch->fresh(), $legacy->fresh()))
        ->toThrow(RuntimeException::class, 'rejeitado na validação');

    lxuExpectDocumentUntouched($legacy);
})->with([
    'rejeitado pelo XSD' => ['<ansTISS><mensagemTISS>incompleto</mensagemTISS></ansTISS>'],
    'malformado'         => ['<ansTISS><mensagemTISS>'],
]);

it('[UPGRADE] sem como resolver o XSD o XML legado continua barrado (validação efetiva) — e nada fica reivindicado', function (bool $requireSchema, string $message) {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    $state    = lxuTransport($document->id);
    $legacy   = lxuMakeLegacy($batch);

    // O XSD some depois da geração (instalação sem os schemas, caminho trocado).
    config(['tiss.schema_base_path' => '/nao/existe', 'tiss.require_schema_validation' => $requireSchema]);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, $message);

    expect($state['requests'])->toBe([])
        ->and(TissTransmission::query()->count())->toBe(0)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::XmlReady);

    lxuExpectDocumentUntouched($legacy);
})->with([
    'exigência desligada (valida só "bem formado")' => [false, 'validação efetiva'],
    'exigência ligada (schema não encontrado)'      => [true, 'Schema XSD não encontrado'],
]);

it('[UPGRADE] a revalidação usa o código da versão do lote e grava os avisos do validador como a geração do XML grava', function () {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    lxuTransport($document->id);
    $legacy = lxuMakeLegacy($batch);

    test()->mock(ValidateTissXmlService::class)
        ->shouldReceive('validate')
        ->once()
        ->withArgs(fn (string $xml, ?string $versionCode) => $xml === $legacy->xml_content && $versionCode === '202603')
        ->andReturn(['valid' => true, 'errors' => ['aviso do libxml'], 'warnings' => ['outro aviso'], 'schema' => '/schemas/202603/tissV4_03_00.xsd']);

    app(SendTissBatchService::class)->send($batch->fresh());

    expect($document->fresh()->validation_errors)->toBe(['aviso do libxml', 'outro aviso'])
        ->and($document->fresh()->metadata['schema'])->toBe('/schemas/202603/tissV4_03_00.xsd');
});

it('XML com a evidência gravada não é validado de novo na hora de enviar', function () {
    $batch    = lxuBatch();
    $document = $batch->xmlDocument;
    $state    = lxuTransport($document->id);

    $validatedAt = $document->validated_at;
    $metadata    = $document->metadata;

    test()->mock(ValidateTissXmlService::class)->shouldNotReceive('validate');

    app(SendTissBatchService::class)->send($batch->fresh());

    expect($state['requests'])->toHaveCount(1)
        ->and($document->fresh()->metadata)->toBe($metadata)
        ->and($document->fresh()->validated_at->equalTo($validatedAt))->toBeTrue();
});
