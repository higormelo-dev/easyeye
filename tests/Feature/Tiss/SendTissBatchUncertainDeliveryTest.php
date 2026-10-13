<?php

declare(strict_types=1);

/**
 * Entrega EM DÚVIDA × erro definitivo — achado via revisão adversarial Codex
 * (16ª rodada): se a operadora aceita o lote e a resposta se perde, o
 * HttpTissTransportClient devolve uma exceção de transporte (sem status HTTP).
 * Isso virava "erro" (lote editável): mudar o CNES ou uma guia e reenviar
 * gerava XML novo e chave nova, e a operadora que já tinha o lote o recebia de
 * novo, sem a deduplicação da chave original.
 *
 * Agora: SEM resposta (exceção de transporte, 408, timeout) a entrega fica em
 * dúvida — transmissão "timeout" e lote ainda "enviando" (travado; o reenvio
 * retoma a MESMA transmissão e a MESMA chave). Só a operadora RESPONDER com
 * erro (HTTP 4xx/5xx), ou o endpoint nem existir, é definitivo ("erro").
 *
 * A dúvida SOBREVIVE às tentativas seguintes (17ª rodada): depois de uma
 * resposta perdida, uma retomada que falha com 401 — ou localmente, com
 * missing_endpoint — não diz nada sobre o 1º POST. O lote continua "enviando",
 * com o mesmo XML e a mesma chave, até a operadora ACEITAR.
 *
 * HTTP 500 também é dúvida (20ª rodada): o servidor falhou ENQUANTO tratava o
 * pedido e pode ter gravado o lote antes de falhar ao montar a resposta. Só a
 * recusa EXPLÍCITA do protocolo no corpo ("Rejeitado") faz do 500 um veredito.
 *
 * Usa HttpTissTransportClient + Http::fake() (mesmo padrão de
 * SendTissBatchRetryAfterFailureTest): o MockTissTransportClient nunca falha.
 */

use App\Domains\Tiss\Actions\DetachGuideFromBatchAction;
use App\Domains\Tiss\Enums\{TissBatchStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Jobs\{GenerateTissBatchXmlJob, SendTissBatchJob};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissOperator, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, SendTissBatchService};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissTransportContract};
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function tudFixture(bool $withEndpoint = true): TissBatch
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    TissEntityOperatorCredential::create([
        'entity_id'           => $entity->id,
        'operator_id'         => $operator->id,
        'environment'         => (string) config('tiss.transport.environment', 'production'),
        'endpoint_submission' => $withEndpoint ? 'https://operadora.teste/tiss' : null,
        'active'              => true,
    ]);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-DUVIDA-0001',
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

    return $batch->fresh();
}

const TUD_ACK_BODY = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

/**
 * Roteiro das respostas do transporte HTTP, uma por requisição ('ack' quando o
 * roteiro acaba): 'lost' = exceção de transporte (resposta perdida, sem status
 * HTTP), 'ack' = aceite, ou um status HTTP de erro.
 *
 * Http::recorded() NÃO guarda a requisição cuja resposta se perdeu (o stub lança
 * antes de o par requisição/resposta ser gravado) — e é justamente a chave dessa
 * que precisa ser comparada com a do reenvio. Por isso o roteiro anota o
 * cabeçalho X-Idempotency-Key de TODA requisição que chegou ao transporte.
 *
 * @param list<int|string> $steps
 */
function tudScript(array $steps): void
{
    $calls = 0;
    tudKeys(reset: true);

    Http::fake(['*' => function ($request) use (&$calls, $steps) {
        tudKeys(record: (string) ($request->header('X-Idempotency-Key')[0] ?? ''));

        $step = $steps[$calls++] ?? 'ack';

        if ($step === 'lost') {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
        }

        return $step === 'ack'
            ? Http::response(TUD_ACK_BODY, 200)
            : Http::response('resposta da operadora', (int) $step);
    }]);
}

/**
 * A chave de idempotência de cada requisição que chegou ao transporte, na ordem
 * (inclusive as de resposta perdida). Sem argumentos, só lê.
 *
 * @return list<string>
 */
function tudKeys(?string $record = null, bool $reset = false): array
{
    static $keys = [];

    if ($reset) {
        $keys = [];
    }

    if ($record !== null) {
        $keys[] = $record;
    }

    return $keys;
}

function tudSetEndpoint(TissBatch $batch, ?string $endpoint): void
{
    TissEntityOperatorCredential::query()->where('entity_id', $batch->entity_id)->update(['endpoint_submission' => $endpoint]);
}

/** A mesma linha que um worker morto no meio do envio deixa: 'sending' com o lease já vencido. */
function tudSeedInterrupted(TissBatch $batch): TissTransmission
{
    $xmlDocument = $batch->xmlDocument;

    return TissTransmission::query()->create([
        'entity_id'       => $batch->entity_id,
        'operator_id'     => $batch->operator_id,
        'batch_id'        => $batch->id,
        'xml_document_id' => $xmlDocument->id,
        'idempotency_key' => hash('sha256', implode('|', [(string) $batch->entity_id, (string) $batch->id, (string) $xmlDocument->file_hash])),
        'status'          => TissTransmissionStatus::Sending->value,
        'queue'           => 'tiss-send',
        'attempt'         => 1,
        'max_attempts'    => 5,
        'requested_at'    => now()->subSeconds((int) config('tiss.transport.lease_seconds') + 60),
    ]);
}

beforeEach(function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
});

it('[CORRIDA] resposta PERDIDA (exceção de transporte, sem status HTTP): transmissão "timeout", lote continua "enviando" e o erro explica a dúvida', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')]);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, 'não sabemos se ela recebeu o lote');

    $transmission = TissTransmission::query()->sole();

    expect($transmission->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($transmission->error_code)->toBe('transport_exception')
        ->and($transmission->http_status)->toBeNull()
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and($batch->fresh()->last_error)->toContain('cURL error 28')
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain');
});

it('[CORRIDA] HTTP 408, 500 (sem recusa explícita) e erro de gateway (502/503/504) também são entrega em dúvida: o servidor da operadora pode ter processado o lote', function (int $status) {
    Http::fake(['*' => Http::response('gateway', $status)]);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, 'não sabemos se ela recebeu o lote');

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain');
})->with([408, 500, 502, 503, 504]);

it('[CORRIDA] HTTP 500 cujo corpo não traz recusa explícita (vazio, página de erro, até protocolo "aceito") é dúvida — a operadora pode ter gravado o lote e falhado ao responder', function (string $body) {
    Http::fake(['*' => Http::response($body, 500)]);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, 'não sabemos se ela recebeu o lote');

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(in_array($batch->fresh()->status, DetachGuideFromBatchAction::EDITABLE_STATUSES, true))->toBeFalse()
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain');
})->with([
    'corpo vazio'                     => '',
    'página HTML de erro do servidor' => '<html><body><h1>Internal Server Error</h1><p>rejeitado por regra interna</p></body></html>',
    'protocolo aceito, mas HTTP 500'  => TUD_ACK_BODY,
]);

it('a recusa explícita no corpo só faz do HTTP 500 um veredito: 408 e erro de gateway com o mesmo corpo continuam sendo dúvida', function (int $status) {
    $rejection = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><statusProtocolo>Rejeitado</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

    Http::fake(['*' => Http::response($rejection, $status)]);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, 'não sabemos se ela recebeu o lote');

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending);
})->with([408, 502, 503, 504]);

it('HTTP 500 com a recusa EXPLÍCITA do protocolo no corpo ("Rejeitado") é o veredito da operadora: definitivo, transmissão "falhou" e lote em "erro" (editável)', function () {
    $rejection = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><statusProtocolo>Rejeitado</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

    Http::fake(['*' => Http::response($rejection, 500)]);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(fn (RuntimeException $e) => expect($e->getMessage())->toBe($rejection));

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Failed)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('failed');
});

it('a operadora RESPONDER com erro de cliente (HTTP 4xx) é definitivo: transmissão "falhou" e lote em "erro" (editável)', function (int $status) {
    Http::fake(['*' => Http::response('rejeitado', $status)]);
    $batch = tudFixture();

    // Erro definitivo: a mensagem é a da operadora, sem o aviso de "entrega em dúvida".
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(fn (RuntimeException $e) => expect($e->getMessage())->toBe('rejeitado'));

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Failed)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('failed');
})->with([400, 401, 403, 404, 409, 422]);

it('endpoint não configurado nunca saiu da clínica: falha definitiva (erro), não dúvida', function () {
    Http::fake();
    $batch = tudFixture(withEndpoint: false);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Failed)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error);
});

it('depois da dúvida o reenvio usa a MESMA chave (cabeçalho X-Idempotency-Key) e a MESMA linha, e confirma o lote', function () {
    tudScript(['lost', 'ack']);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    $second = app(SendTissBatchService::class)->send($batch->fresh());

    expect(tudKeys())->toHaveCount(2)
        ->and(array_unique(tudKeys()))->toHaveCount(1)
        ->and(tudKeys()[0])->not->toBe('')
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($second->attempt)->toBe(2)
        ->and($second->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('SendTissBatchJob esgotou as tentativas com a entrega EM DÚVIDA: o lote continua "enviando" (só registra o motivo), nunca vira "erro"', function () {
    $batch = tudFixture();
    $batch->update(['status' => TissBatchStatus::Sending->value]);

    (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id))->failed(new RuntimeException('tempo esgotado'));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and($batch->fresh()->last_error)->toContain('tempo esgotado');
});

it('SendTissBatchJob esgotou as tentativas sem nada em dúvida (falha antes de enviar): continua virando "erro"', function () {
    $batch = tudFixture(); // xml_ready

    (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id))->failed(new RuntimeException('credencial ausente'));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and($batch->fresh()->last_error)->toContain('credencial ausente');
});

// ─────────────────────────────────────────────────────────────────────────────
// 17ª rodada: a dúvida sobrevive às tentativas seguintes que falham
// ─────────────────────────────────────────────────────────────────────────────

it('[CORRIDA] dúvida + a operadora responde com erro HTTP na retomada: o erro não prova que o 1º envio foi recusado — o lote segue "enviando", com o mesmo XML e a mesma chave', function (int $status, string $messageKey) {
    tudScript(['lost', $status, 'ack']);
    $batch = tudFixture();
    $xmlId = $batch->xml_document_id;

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    // 4xx: esta tentativa foi respondida (delivery_still_unknown); 500: segue sem resposta conclusiva (delivery_unknown).
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, __($messageKey, ['reason' => 'resposta da operadora']));

    $transmission = TissTransmission::query()->sole();

    // O estado de dúvida não foi limpo: nada de guia nova/retirada, nada de XML novo.
    expect($transmission->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($transmission->attempt)->toBe(2)
        ->and($transmission->http_status)->toBe($status)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(in_array($batch->fresh()->status, DetachGuideFromBatchAction::EDITABLE_STATUSES, true))->toBeFalse()
        ->and($batch->fresh()->last_error)->toContain('resposta da operadora')
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain')
        ->and(app(GenerateTissXmlService::class)->generateBatch($batch->fresh())->id)->toBe($xmlId);

    // Só o ACEITE encerra a dúvida — e sempre com a chave do 1º POST.
    $third = app(SendTissBatchService::class)->send($batch->fresh());

    expect(tudKeys())->toHaveCount(3)
        ->and(array_unique(tudKeys()))->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($third->attempt)->toBe(3)
        ->and($third->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
})->with([
    'HTTP 401' => [401, 'tiss_send.delivery_still_unknown'],
    'HTTP 403' => [403, 'tiss_send.delivery_still_unknown'],
    'HTTP 404' => [404, 'tiss_send.delivery_still_unknown'],
    'HTTP 409' => [409, 'tiss_send.delivery_still_unknown'],
    'HTTP 422' => [422, 'tiss_send.delivery_still_unknown'],
    'HTTP 500' => [500, 'tiss_send.delivery_unknown'],
]);

it('[CORRIDA] dúvida + falha LOCAL na retomada (endpoint removido: nada saiu da clínica): o lote continua travado e, com o endpoint de volta, o reenvio leva a MESMA chave', function () {
    tudScript(['lost', 'ack']);
    $batch = tudFixture();
    $xmlId = $batch->xml_document_id;

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    tudSetEndpoint($batch, null);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, __('tiss_send.delivery_still_unknown', ['reason' => 'Endpoint de submissão TISS não configurado para a operadora.']));

    expect(tudKeys())->toHaveCount(1); // só a 1ª chegou ao transporte: a 2ª nem saiu da clínica

    $transmission = TissTransmission::query()->sole();

    expect($transmission->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($transmission->attempt)->toBe(2)
        ->and($transmission->error_code)->toBe('missing_endpoint')
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(in_array($batch->fresh()->status, DetachGuideFromBatchAction::EDITABLE_STATUSES, true))->toBeFalse()
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain')
        ->and(app(GenerateTissXmlService::class)->generateBatch($batch->fresh())->id)->toBe($xmlId);

    tudSetEndpoint($batch, 'https://operadora.teste/tiss');

    $third = app(SendTissBatchService::class)->send($batch->fresh());

    expect(tudKeys())->toHaveCount(2)
        ->and(array_unique(tudKeys()))->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($third->attempt)->toBe(3)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('[CORRIDA] tentativa INTERROMPIDA (sending com lease vencido) também é dúvida: o erro da retomada não libera o lote', function () {
    tudScript([401, 'ack']);
    $batch = tudFixture();
    $batch->update(['status' => TissBatchStatus::Sending->value]);
    $interrupted = tudSeedInterrupted($batch);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, __('tiss_send.delivery_still_unknown', ['reason' => 'resposta da operadora']));

    $transmission = TissTransmission::query()->sole();

    expect($transmission->id)->toBe($interrupted->id)
        ->and($transmission->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($transmission->attempt)->toBe(2)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending);

    $third = app(SendTissBatchService::class)->send($batch->fresh());

    expect(tudKeys())->toHaveCount(2)
        ->and(array_unique(tudKeys()))->toBe([$interrupted->idempotency_key])
        ->and($third->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('[CORRIDA] a dúvida pode nascer numa tentativa posterior: erro definitivo (422) → resposta perdida → erro (401) segue travado, com a mesma chave desde o 1º POST', function () {
    tudScript([422, 'lost', 401, 'ack']);
    $batch = tudFixture();

    // 1ª: a operadora respondeu 422 — sem dúvida nenhuma, lote editável.
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);
    expect($batch->fresh()->status)->toBe(TissBatchStatus::Error);

    // 2ª: agora a resposta se perdeu → dúvida.
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);
    expect($batch->fresh()->status)->toBe(TissBatchStatus::Sending);

    // 3ª: erro devolvido — não resolve a dúvida da 2ª.
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending);

    app(SendTissBatchService::class)->send($batch->fresh());

    expect(array_unique(tudKeys()))->toHaveCount(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('[CORRIDA] force=true numa entrega em dúvida também preserva a dúvida quando a operadora responde com erro', function () {
    tudScript(['lost', 401]);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))->toThrow(RuntimeException::class);

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain');
});

it('[CORRIDA] retomada que MORRE sem gravar resposta (worker morto) não apaga a marca de dúvida da chave, e o erro da retomada seguinte ainda não libera o lote', function () {
    // Transporte que perde a 1ª resposta, MORRE na 2ª (a exceção escapa do cliente
    // HTTP real, que a trataria — aqui simula o worker caindo), recusa a 3ª (401) e aceita a 4ª.
    $transport = new class() implements TissTransportContract {
        /** @var list<string> */
        public array $keys = [];

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->keys[] = $idempotencyKey;
            $call         = count($this->keys);

            if ($call === 2) {
                throw new RuntimeException('worker morto no meio do envio');
            }

            $failure = ['ok' => false, 'response_body' => null, 'protocol_number' => null, 'protocol_status' => null, 'response_time_ms' => 1];

            return match ($call) {
                1       => $failure + ['status_code' => null, 'error_code' => 'transport_exception', 'error_message' => 'cURL error 28: Operation timed out'],
                3       => $failure + ['status_code' => 401, 'error_code' => 'http_401', 'error_message' => 'credencial recusada'],
                default => ['ok' => true, 'status_code' => 200, 'response_body' => TUD_ACK_BODY, 'protocol_number' => 'PRT-1', 'protocol_status' => 'accepted', 'error_code' => null, 'error_message' => null, 'response_time_ms' => 1],
            };
        }
    };
    app()->instance(TissTransportContract::class, $transport);

    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class, 'worker morto');

    // Morreu no meio: a linha ficou "enviando", mas a chave segue marcada em dúvida (não virou "reservada").
    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Sending)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain')
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending);

    $this->travel((int) config('tiss.transport.lease_seconds') + 60)->seconds();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain');

    $final = app(SendTissBatchService::class)->send($batch->fresh());

    expect($transport->keys)->toHaveCount(4)
        ->and(array_unique($transport->keys))->toHaveCount(1)
        ->and($final->attempt)->toBe(4)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

it('sem dúvida anterior o erro continua definitivo: 422 → 401 deixa o lote em "erro" (editável)', function () {
    tudScript([422, 401]);
    $batch = tudFixture();

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);
    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))->toThrow(RuntimeException::class);

    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Failed)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('failed');
});

it('GenerateTissBatchXmlJob esgotou as tentativas com o lote enviando/enviado: o lote NÃO vira "erro" (falha local não limpa a dúvida) e nada nele é tocado', function (TissBatchStatus $status) {
    $batch = tudFixture();
    $batch->update(['status' => $status->value, 'last_error' => 'cURL error 28: Operation timed out']);

    (new GenerateTissBatchXmlJob((string) $batch->id, (string) $batch->entity_id))->failed(new RuntimeException('deadlock detectado'));

    expect($batch->fresh()->status)->toBe($status)
        ->and($batch->fresh()->last_error)->toBe('cURL error 28: Operation timed out');
})->with([
    'enviando (dúvida ou em voo)' => TissBatchStatus::Sending,
    'enviado'                     => TissBatchStatus::Sent,
    'confirmado'                  => TissBatchStatus::Acknowledged,
    'processado'                  => TissBatchStatus::Processed,
]);

it('GenerateTissBatchXmlJob esgotou as tentativas num lote que ainda não saiu: continua virando "erro"', function (TissBatchStatus $status) {
    $batch = tudFixture();
    $batch->update(['status' => $status->value]);

    (new GenerateTissBatchXmlJob((string) $batch->id, (string) $batch->entity_id))->failed(new RuntimeException('XSD indisponível'));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and($batch->fresh()->last_error)->toContain('XSD indisponível');
})->with([TissBatchStatus::Open, TissBatchStatus::Closed, TissBatchStatus::XmlReady]);
