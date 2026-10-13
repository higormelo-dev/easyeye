<?php

declare(strict_types=1);

/**
 * Reenvio FORÇADO (force=true) de uma transmissão já CONFIRMADA — achado via
 * revisão adversarial Codex (18ª rodada): o reenvio reescrevia a linha confirmada
 * como "enviando" e o lote como "enviando"; se ele falhasse (401, endpoint
 * ausente...), a transmissão virava "falhou", o lote "erro" (editável) e a chave
 * "failed" — mesmo com o 1º envio aceito. Mudar o dado e gerar o XML de novo
 * saía com chave nova: a operadora que já tinha o lote o receberia outra vez.
 * E o reenvio rebaixava lote e guias de "processado"/"pago" para "enviado".
 *
 * Agora, com o lote já ENTREGUE (enviado, confirmado, processado, pago...), o
 * reenvio forçado não mexe na confirmação, no status do lote, nas guias nem na
 * chave: a falha é registrada à parte (metadata da transmissão + histórico) e o
 * sucesso só atualiza a resposta da transmissão.
 *
 * Usa HttpTissTransportClient + Http::fake() (o MockTissTransportClient nunca falha).
 */

use App\Domains\Tiss\Actions\DetachGuideFromBatchAction;
use App\Domains\Tiss\Enums\{TissBatchStatus, TissProtocolStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Jobs\SendTissBatchJob;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissOperator, TissProtocol, TissStatusHistory, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, SendTissBatchService};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissTransportContract};
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function tfrFixture(bool $withEndpoint = true): TissBatch
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
        'batch_number'    => 'LOTE-FORCADO-0001',
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

const TFR_ACK_BODY = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

/** Mesmo protocolo, sem o status: a resposta de um reenvio que só confirma o recebimento. */
const TFR_ACK_NO_STATUS = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

/**
 * Roteiro das respostas do transporte HTTP, uma por requisição ('ack' quando o
 * roteiro acaba): 'lost' = exceção de transporte (resposta perdida), 'ack' =
 * aceite, 'ack_no_status' = aceite sem status de protocolo, ou um status HTTP de
 * erro. A chave de idempotência de TODA requisição que chegou ao transporte é
 * anotada aqui (Http::recorded() não guarda a de resposta perdida).
 *
 * @param list<int|string> $steps
 */
function tfrScript(array $steps): void
{
    $calls = 0;
    tfrKeys(reset: true);

    Http::fake(['*' => function ($request) use (&$calls, $steps) {
        tfrKeys(record: (string) ($request->header('X-Idempotency-Key')[0] ?? ''));

        $step = $steps[$calls++] ?? 'ack';

        if ($step === 'lost') {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
        }

        return match ($step) {
            'ack'           => Http::response(TFR_ACK_BODY, 200),
            'ack_no_status' => Http::response(TFR_ACK_NO_STATUS, 200),
            default         => Http::response('resposta da operadora', (int) $step),
        };
    }]);
}

/** @return list<string> a chave de cada requisição que chegou ao transporte, na ordem */
function tfrKeys(?string $record = null, bool $reset = false): array
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

function tfrSetEndpoint(TissBatch $batch, ?string $endpoint): void
{
    TissEntityOperatorCredential::query()->where('entity_id', $batch->entity_id)->update(['endpoint_submission' => $endpoint]);
}

beforeEach(function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Falha do reenvio forçado: a confirmação do 1º envio é preservada
// ─────────────────────────────────────────────────────────────────────────────

it('[CONFIRMADO] reenvio forçado que FALHA não desfaz a confirmação: transmissão confirmada, lote enviado, chave concluída — e a falha fica registrada à parte', function (int|string $step, string $reason, string $errorCode, ?int $httpStatus) {
    tfrScript(['ack', $step]);
    $batch = tfrFixture();
    $xmlId = $batch->xml_document_id;

    $first       = app(SendTissBatchService::class)->send($batch->fresh());
    $ackPayload  = $first->response_payload;
    $protocolId  = $first->protocol_id;
    $submittedAt = $batch->fresh()->submitted_at;

    expect($first->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))
        ->toThrow(RuntimeException::class, __('tiss_send.forced_resend_failed', ['reason' => $reason]));

    $transmission = TissTransmission::query()->sole();
    $failure      = $transmission->metadata['forced_resend']['last_failure'];

    // A confirmação do 1º envio segue de pé: resposta, protocolo, status.
    expect($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($transmission->attempt)->toBe(2)
        ->and($transmission->protocol_id)->toBe($protocolId)
        ->and($transmission->http_status)->toBe(200)
        ->and($transmission->error_code)->toBeNull()
        ->and($transmission->response_payload)->toBe($ackPayload);

    // A falha do reenvio é registrada à parte — e o marcador de "em voo" sai.
    expect($failure['error_code'])->toBe($errorCode)
        ->and($failure['http_status'])->toBe($httpStatus)
        ->and($failure['attempt'])->toBe(2)
        ->and($transmission->metadata['forced_resend'])->not->toHaveKey('started_at');

    expect(TissStatusHistory::query()->where('context_type', 'transmission')->where('context_id', $transmission->id)->where('reason', 'like', '%confirmação da operadora foi preservada%')->exists())->toBeTrue();

    // O lote segue entregue e imutável; a chave segue concluída.
    expect($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and($batch->fresh()->last_error)->toBeNull()
        ->and($batch->fresh()->submitted_at->equalTo($submittedAt))->toBeTrue()
        ->and(in_array($batch->fresh()->status, DetachGuideFromBatchAction::EDITABLE_STATUSES, true))->toBeFalse()
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed')
        ->and(app(GenerateTissXmlService::class)->generateBatch($batch->fresh())->id)->toBe($xmlId);

    // E um envio normal continua devolvendo a confirmada, sem requisição nova.
    $count = count(tfrKeys());
    $again = app(SendTissBatchService::class)->send($batch->fresh());

    expect($again->id)->toBe($transmission->id)
        ->and(tfrKeys())->toHaveCount($count)
        ->and(TissTransmission::query()->count())->toBe(1);
})->with([
    'HTTP 401'         => [401, 'resposta da operadora', 'http_401', 401],
    'HTTP 403'         => [403, 'resposta da operadora', 'http_403', 403],
    'HTTP 422'         => [422, 'resposta da operadora', 'http_422', 422],
    'HTTP 500'         => [500, 'resposta da operadora', 'http_500', 500],
    'HTTP 503'         => [503, 'resposta da operadora', 'http_503', 503],
    'resposta perdida' => ['lost', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', 'transport_exception', null],
]);

it('[CONFIRMADO] reenvio forçado com o endpoint removido (falha local, nada saiu da clínica) também preserva a confirmação', function () {
    tfrScript(['ack']);
    $batch = tfrFixture();

    app(SendTissBatchService::class)->send($batch->fresh());
    tfrSetEndpoint($batch, null);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))
        ->toThrow(RuntimeException::class, __('tiss_send.forced_resend_failed', ['reason' => 'Endpoint de submissão TISS não configurado para a operadora.']));

    $transmission = TissTransmission::query()->sole();

    expect(tfrKeys())->toHaveCount(1) // o reenvio nem saiu
        ->and($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($transmission->metadata['forced_resend']['last_failure']['error_code'])->toBe('missing_endpoint')
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

// ─────────────────────────────────────────────────────────────────────────────
// Reenvio forçado que DÁ CERTO: só a resposta é atualizada
// ─────────────────────────────────────────────────────────────────────────────

it('reenvio forçado que dá certo atualiza a resposta da MESMA transmissão (tentativa 2, mesma chave) sem recuar o lote nem o protocolo', function () {
    tfrScript(['ack', 'ack_no_status']);
    $batch = tfrFixture();

    $first       = app(SendTissBatchService::class)->send($batch->fresh());
    $submittedAt = $batch->fresh()->submitted_at;

    // O protocolo já andou depois do aceite (retorno processado).
    TissProtocol::query()->sole()->update(['status' => TissProtocolStatus::Processing->value]);

    $this->travel(30)->seconds();

    $second = app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    expect($second->id)->toBe($first->id)
        ->and($second->attempt)->toBe(2)
        ->and($second->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($second->response_payload)->toBe(TFR_ACK_NO_STATUS)
        ->and($second->metadata['forced_resend'])->not->toHaveKey('started_at')
        ->and($second->metadata['forced_resend'])->toHaveKey('last_success_at')
        ->and(array_unique(tfrKeys()))->toHaveCount(1)
        ->and(tfrKeys())->toHaveCount(2)
        ->and(TissProtocol::query()->sole()->status)->toBe(TissProtocolStatus::Processing)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and($batch->fresh()->submitted_at->equalTo($submittedAt))->toBeTrue()
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

it('falha de um reenvio forçado depois de um bem-sucedido apaga o registro da falha antiga (e vice-versa): só o último resultado do reenvio fica', function () {
    tfrScript(['ack', 401, 'ack_no_status']);
    $batch = tfrFixture();

    app(SendTissBatchService::class)->send($batch->fresh());

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))->toThrow(RuntimeException::class);
    expect(TissTransmission::query()->sole()->metadata['forced_resend'])->toHaveKey('last_failure');

    app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    $forced = TissTransmission::query()->sole()->metadata['forced_resend'];

    expect($forced)->not->toHaveKey('last_failure')
        ->and($forced)->toHaveKey('last_success_at')
        ->and(TissTransmission::query()->sole()->attempt)->toBe(3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Concorrência e worker morto
// ─────────────────────────────────────────────────────────────────────────────

it('[CORRIDA] reenvio forçado ainda em voo (marcador vigente) não é duplicado; vencido (worker morto), é retomado', function () {
    tfrScript(['ack']);
    $batch = tfrFixture();

    $first = app(SendTissBatchService::class)->send($batch->fresh());

    // Outro reenvio forçado começou agora e ainda não respondeu.
    $first->update(['metadata' => ['forced_resend' => ['started_at' => now()->toIso8601String(), 'attempt' => 2]]]);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))
        ->toThrow(RuntimeException::class, __('tiss_send.transmission_in_progress'));

    expect(tfrKeys())->toHaveCount(1) // nada novo saiu
        ->and(TissTransmission::query()->sole()->attempt)->toBe(1);

    // throwOnError=false devolve a confirmada, sem enviar.
    $quiet = app(SendTissBatchService::class)->send($batch->fresh(), force: true, throwOnError: false);

    expect($quiet->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(tfrKeys())->toHaveCount(1);

    // O worker daquele reenvio morreu: passado o lease, o reenvio forçado segue.
    $this->travel((int) config('tiss.transport.lease_seconds') + 60)->seconds();

    $resumed = app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    expect($resumed->attempt)->toBe(2)
        ->and($resumed->metadata['forced_resend'])->not->toHaveKey('started_at')
        ->and(tfrKeys())->toHaveCount(2);
});

it('[CORRIDA] worker morto no meio do reenvio forçado: confirmação e lote intactos, envio normal não manda nada e o forçado seguinte conclui', function () {
    // Transporte que aceita o 1º envio e MORRE no reenvio forçado (a exceção escapa do
    // cliente HTTP real, que a trataria — aqui simula o worker caindo).
    $transport = new class() implements TissTransportContract {
        /** @var list<string> */
        public array $keys = [];

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->keys[] = $idempotencyKey;

            if (count($this->keys) === 2) {
                throw new RuntimeException('worker morto no meio do envio');
            }

            return ['ok' => true, 'status_code' => 200, 'response_body' => TFR_ACK_BODY, 'protocol_number' => 'PRT-1', 'protocol_status' => 'accepted', 'error_code' => null, 'error_message' => null, 'response_time_ms' => 1];
        }
    };
    app()->instance(TissTransportContract::class, $transport);

    $batch = tfrFixture();

    app(SendTissBatchService::class)->send($batch->fresh());

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))
        ->toThrow(RuntimeException::class, 'worker morto');

    $transmission = TissTransmission::query()->sole();

    expect($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($transmission->metadata['forced_resend'])->toHaveKey('started_at')
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');

    // Envio normal: a confirmada, sem requisição nova (nada de "preso em enviando").
    app(SendTissBatchService::class)->send($batch->fresh());
    expect($transport->keys)->toHaveCount(2);

    $this->travel((int) config('tiss.transport.lease_seconds') + 60)->seconds();

    $done = app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    expect($transport->keys)->toHaveCount(3)
        ->and(array_unique($transport->keys))->toHaveCount(1)
        ->and($done->attempt)->toBe(3)
        ->and($done->metadata['forced_resend'])->not->toHaveKey('started_at')
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

// ─────────────────────────────────────────────────────────────────────────────
// O que NÃO muda: lote rejeitado pelo retorno da operadora continua reenviável à força
// ─────────────────────────────────────────────────────────────────────────────

it('lote em "erro" depois de a operadora REJEITAR por retorno: o reenvio forçado continua levando o lote a "enviado" quando dá certo', function () {
    tfrScript(['ack', 'ack']);
    $batch = tfrFixture();

    app(SendTissBatchService::class)->send($batch->fresh());

    // ProcessTissReturnService mapeia o retorno "rejeitado" para lote em erro; a transmissão segue confirmada.
    $batch->update(['status' => TissBatchStatus::Error->value]);

    $second = app(SendTissBatchService::class)->send($batch->fresh(), force: true);

    expect($second->attempt)->toBe(2)
        ->and($second->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

it('lote em "erro" depois de a operadora REJEITAR por retorno: o reenvio forçado que falha o mantém em "erro" (já era editável: a operadora rejeitou)', function () {
    tfrScript(['ack', 401]);
    $batch = tfrFixture();

    app(SendTissBatchService::class)->send($batch->fresh());
    $batch->update(['status' => TissBatchStatus::Error->value]);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))
        ->toThrow(fn (RuntimeException $e) => expect($e->getMessage())->toBe('resposta da operadora'));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Failed);
});

it('[CORRIDA] worker morto num reenvio forçado de lote em "erro" (rejeitado por retorno): o envio NORMAL seguinte retoma a linha em vez de devolvê-la como "já enviada"', function () {
    $transport = new class() implements TissTransportContract {
        /** @var list<string> */
        public array $keys = [];

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->keys[] = $idempotencyKey;

            if (count($this->keys) === 2) {
                throw new RuntimeException('worker morto no meio do envio');
            }

            return ['ok' => true, 'status_code' => 200, 'response_body' => TFR_ACK_BODY, 'protocol_number' => 'PRT-1', 'protocol_status' => 'accepted', 'error_code' => null, 'error_message' => null, 'response_time_ms' => 1];
        }
    };
    app()->instance(TissTransportContract::class, $transport);

    $batch = tfrFixture();

    app(SendTissBatchService::class)->send($batch->fresh());
    $batch->update(['status' => TissBatchStatus::Error->value]);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh(), force: true))
        ->toThrow(RuntimeException::class, 'worker morto');

    // Aqui o lote NÃO estava entregue: o reenvio o trava em "enviando" (como sempre), com a chave marcada "forced".
    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Sending)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('forced');

    $this->travel((int) config('tiss.transport.lease_seconds') + 60)->seconds();

    $resumed = app(SendTissBatchService::class)->send($batch->fresh());

    expect($transport->keys)->toHaveCount(3) // a retomada mandou de verdade
        ->and($resumed->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

// ─────────────────────────────────────────────────────────────────────────────
// Job: esgotar as tentativas de um reenvio forçado não rebaixa lote entregue
// ─────────────────────────────────────────────────────────────────────────────

it('SendTissBatchJob (forçado) esgotou as tentativas com o lote já entregue: ele NÃO vira "erro" e nada nele é tocado', function (TissBatchStatus $status) {
    $batch = tfrFixture();
    $batch->update(['status' => $status->value, 'last_error' => null]);

    (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id, true))->failed(new RuntimeException('HTTP 401'));

    expect($batch->fresh()->status)->toBe($status)
        ->and($batch->fresh()->last_error)->toBeNull();
})->with([
    'enviado'           => TissBatchStatus::Sent,
    'confirmado'        => TissBatchStatus::Acknowledged,
    'processado'        => TissBatchStatus::Processed,
    'parcialmente pago' => TissBatchStatus::PartiallyPaid,
    'pago'              => TissBatchStatus::Paid,
]);
