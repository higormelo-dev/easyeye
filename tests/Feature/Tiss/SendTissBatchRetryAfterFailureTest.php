<?php

declare(strict_types=1);

/**
 * send() criava uma linha NOVA de TissTransmission a cada chamada — só
 * funcionava na 1ª tentativa. Um reenvio (retry da fila após falha, ou
 * force=true manual) colidia com a linha da 1ª tentativa (já FALHADA) na
 * unique(entity_id, idempotency_key); o catch dessa colisão "adotava" a
 * linha existente e retornava sem nunca chamar o transporte de novo e sem
 * lançar erro — a fila via a tentativa como concluída com sucesso e parava
 * de tentar, deixando o lote preso em erro pra sempre (achado via revisão
 * adversarial Codex no diff desta sessão).
 *
 * Usa HttpTissTransportClient + Http::fake() (mesmo padrão de
 * SendTissBatchSchemaGuardTest.php) porque o MockTissTransportClient
 * (padrão do projeto) sempre devolve ok=true — não dá pra simular falha
 * nem contar requisições de verdade com ele.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Jobs\SendTissBatchJob;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissOperator, TissStatusHistory, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\SendTissBatchService;
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissTransportContract};
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function tissRetryFixture(): TissBatch
{
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
        'batch_number'    => 'LOTE-RETRY-0001',
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
        // Precisa passar pelo guard do EE-07 (transporte HTTP real exige
        // validação XSD efetiva) — valor não precisa existir em disco, o
        // guard só checa presença (ver SendTissBatchSchemaGuardTest.php).
        'metadata' => ['schema' => storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd')],
    ]);

    $batch->update(['xml_document_id' => $xmlDocument->id]);

    return $batch->fresh();
}

it('[GAP] reenvio depois de FALHA (retry da fila) chama o transporte de novo, reaproveitando a MESMA linha', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fakeSequence()
        ->push('dados inválidos', 422)
        ->push('<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>', 200);

    $batch = tissRetryFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class);

    $failed = TissTransmission::query()->sole();
    expect($failed->status)->toBe(TissTransmissionStatus::Failed)
        ->and($failed->attempt)->toBe(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error);

    // Retry da fila (mesmo batch, mesmo conteúdo — mesma chave de idempotência).
    $second = app(SendTissBatchService::class)->send($batch->fresh());

    Http::assertSentCount(2);
    expect($second->id)->toBe($failed->id)
        ->and($second->attempt)->toBe(2)
        ->and($second->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('[GAP] 2ª falha também lança erro e incrementa a tentativa — não fica "resolvida" em silêncio', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fakeSequence()
        ->push('dados inválidos', 422)
        ->push('requisição inválida', 400);

    $batch = tissRetryFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    Http::assertSentCount(2);
    $transmission = TissTransmission::query()->sole();
    expect($transmission->attempt)->toBe(2)
        ->and($transmission->status)->toBe(TissTransmissionStatus::Failed)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error);
});

it('[GAP] force=true depois de ACKNOWLEDGED chama o transporte de novo de verdade (não só devolve a linha antiga)', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fakeSequence()
        ->push('<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>', 200)
        ->push('<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-2</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>', 200);

    $batch = tissRetryFixture();

    $first = app(SendTissBatchService::class)->send($batch->fresh());
    expect($first->attempt)->toBe(1);

    $second = app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    Http::assertSentCount(2);
    expect($second->id)->toBe($first->id)
        ->and($second->attempt)->toBe(2)
        ->and(TissTransmission::query()->count())->toBe(1);
});

/**
 * Semeia a linha de uma tentativa que ficou em 'sending' (worker morto no
 * meio, ou falha ao gravar o retorno) — o estado que o achado da 2ª revisão
 * adversarial Codex descreve. $requestedAgoSeconds controla há quanto tempo
 * ela começou (a vigência do lease é contada de requested_at).
 */
function tissRetrySeedSending(TissBatch $batch, int $requestedAgoSeconds, int $attempt = 1): TissTransmission
{
    $xmlDocument    = $batch->xmlDocument;
    $idempotencyKey = hash('sha256', implode('|', [(string) $batch->entity_id, (string) $batch->id, (string) $xmlDocument->file_hash]));

    return TissTransmission::query()->create([
        'entity_id'       => $batch->entity_id,
        'operator_id'     => $batch->operator_id,
        'batch_id'        => $batch->id,
        'xml_document_id' => $xmlDocument->id,
        'idempotency_key' => $idempotencyKey,
        'status'          => TissTransmissionStatus::Sending->value,
        'queue'           => 'tiss-send',
        'attempt'         => $attempt,
        'max_attempts'    => 5,
        'requested_at'    => now()->subSeconds($requestedAgoSeconds),
    ]);
}

const TISS_ACK_BODY = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

it('[SEGURANÇA] transmissão com lease VIGENTE (outra tentativa pode estar em voo) nunca é duplicada, nem com force — e falha em vez de "concluir" em silêncio', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake(); // qualquer requisição aqui seria um erro de teste

    $batch    = tissRetryFixture();
    $inFlight = tissRetrySeedSending($batch, requestedAgoSeconds: 5);

    // Antes: devolvia a linha sem erro nem envio — o job da fila terminava
    // "com sucesso" e parava de tentar (achado via revisão adversarial Codex).
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))
        ->toThrow(RuntimeException::class, 'em andamento');

    Http::assertNothingSent();
    expect($inFlight->fresh()->attempt)->toBe(1)
        ->and($inFlight->fresh()->status)->toBe(TissTransmissionStatus::Sending)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('lease vigente com throwOnError=false devolve a linha em voo (o chamador inspeciona o status), sem enviar', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake();

    $batch    = tissRetryFixture();
    $inFlight = tissRetrySeedSending($batch, requestedAgoSeconds: 5);

    $result = app(SendTissBatchService::class)->send($batch->fresh(), throwOnError: false);

    Http::assertNothingSent();
    expect($result->id)->toBe($inFlight->id)
        ->and($result->status)->toBe(TissTransmissionStatus::Sending);
});

it('[GAP] tentativa INTERROMPIDA (sending com lease expirado) é retomada no retry da fila: chama o transporte de novo, mesma linha, mesma chave de idempotência — achado via revisão adversarial Codex', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake(['*' => Http::response(TISS_ACK_BODY, 200)]);

    $batch       = tissRetryFixture();
    $interrupted = tissRetrySeedSending($batch, requestedAgoSeconds: (int) config('tiss.transport.lease_seconds') + 60);

    $result = app(SendTissBatchService::class)->send($batch->fresh());

    Http::assertSentCount(1);
    // Mesma chave de idempotência da tentativa interrompida: a operadora que
    // honra o cabeçalho deduplica se aquela tentativa chegou a entregar.
    Http::assertSent(fn ($request) => $request->header('X-Idempotency-Key')[0] === $interrupted->idempotency_key);

    expect($result->id)->toBe($interrupted->id)
        ->and($result->attempt)->toBe(2)
        ->and($result->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('[GAP] tentativa interrompida também é retomada com force=true (antes: force=true "concluía" sem enviar nada)', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake(['*' => Http::response(TISS_ACK_BODY, 200)]);

    $batch       = tissRetryFixture();
    $interrupted = tissRetrySeedSending($batch, requestedAgoSeconds: (int) config('tiss.transport.lease_seconds') + 60);

    $result = app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    Http::assertSentCount(1);
    expect($result->id)->toBe($interrupted->id)
        ->and($result->status)->toBe(TissTransmissionStatus::Acknowledged);
});

it('retomada de tentativa interrompida deixa rastro no histórico de status (auditoria: sending → timeout → sending)', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake(['*' => Http::response(TISS_ACK_BODY, 200)]);

    $batch       = tissRetryFixture();
    $interrupted = tissRetrySeedSending($batch, requestedAgoSeconds: (int) config('tiss.transport.lease_seconds') + 60);

    app(SendTissBatchService::class)->send($batch->fresh());

    $history = TissStatusHistory::query()
        ->where('context_type', 'transmission')
        ->where('context_id', $interrupted->id)
        ->orderBy('changed_at')
        ->get(['previous_status', 'current_status', 'payload']);

    $interruption = $history->first(fn ($row) => $row->previous_status === 'sending' && $row->current_status === 'timeout');

    expect($interruption)->not->toBeNull()
        ->and($interruption->payload['attempt'])->toBe(1);
});

it('[GAP] SendTissBatchJob com tentativa interrompida pendente realmente envia — não termina "com sucesso" sem enviar', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake(['*' => Http::response(TISS_ACK_BODY, 200)]);

    $batch = tissRetryFixture();
    tissRetrySeedSending($batch, requestedAgoSeconds: (int) config('tiss.transport.lease_seconds') + 60);

    (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id))->handle(app(SendTissBatchService::class));

    Http::assertSentCount(1);
    expect($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('SendTissBatchJob com lease vigente FALHA (a fila tenta de novo mais tarde), nunca conclui em silêncio', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake();

    $batch = tissRetryFixture();
    tissRetrySeedSending($batch, requestedAgoSeconds: 5);

    expect(fn () => (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id))->handle(app(SendTissBatchService::class)))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('o lease é maior que o maior tempo de uma tentativa viva (timeout do job + timeouts HTTP) — senão dois workers enviam o MESMO lote ao mesmo tempo', function () {
    $jobTimeout = (new ReflectionClass(SendTissBatchJob::class))->getDefaultProperties()['timeout'];
    $http       = (int) config('tiss.transport.http.timeout_seconds') + (int) config('tiss.transport.http.connect_timeout_seconds');

    expect((int) config('tiss.transport.lease_seconds'))
        ->toBeGreaterThan($jobTimeout)
        ->toBeGreaterThan($http);
});
