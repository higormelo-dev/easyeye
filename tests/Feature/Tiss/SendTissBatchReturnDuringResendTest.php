<?php

declare(strict_types=1);

/**
 * Reenvio × retorno da operadora processado ENQUANTO a requisição está em voo —
 * achado via revisão adversarial Codex (22ª rodada). O reenvio forçado de um lote
 * rejeitado por retorno (lote em "erro", transmissão confirmada) reivindica a
 * transmissão (lote "enviando", comitado) e só então chama o transporte, sem
 * segurar trava. Nesse intervalo o ProcessTissReturnService pode comitar um
 * retorno ACEITO: lote processado/parcialmente pago, guias pagas, protocolo
 * aceito. O fechamento do reenvio decidia pelo retrato que a tentativa guardou:
 * a falha (401...) devolvia o lote a "erro" — editável, com XML e chave novos na
 * próxima geração para um lote que a operadora já aceitou — e o sucesso o
 * rebaixava, e às guias pagas, para "enviado".
 *
 * Agora o fechamento relê o lote sob a trava e, se ele ficou ENTREGUE, só registra
 * o resultado (transmissão, protocolo sem recuar, chave): lote e guias seguem como
 * o retorno os deixou. A mesma regra vale na reivindicação: lote entregue por
 * retorno não é "retomado" (e rebaixado) por um reenvio.
 *
 * O "retorno no meio do voo" é processado de dentro do próprio transporte (Http::fake);
 * a corrida com duas conexões de verdade está em tests/Integration/TissReturnDuringResendTest.php.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissGuide, TissIdempotencyKey, TissOperator, TissProtocol, TissReturn, TissStatusHistory, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{ProcessTissReturnService, SendTissBatchService};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissTransportContract};
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const TRD_ACK_BODY = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

/** Aceite que repete o protocolo SEM status — o que um reenvio costuma devolver. */
const TRD_ACK_NO_STATUS = '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>';

/**
 * Lote pronto para enviar (XML validado) com 2 guias anexadas.
 *
 * @return array{batch: TissBatch, guides: list<TissGuide>}
 */
function trdFixture(): array
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
        'batch_number'    => 'LOTE-RETORNO-ENVIO-0001',
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

    return ['batch' => $batch->fresh(), 'guides' => $guides];
}

/** Retorno da operadora ainda não processado, ligado ao protocolo. */
function trdReturn(TissBatch $batch, TissProtocol $protocol, array $summary): TissReturn
{
    return TissReturn::create([
        'entity_id'   => $batch->entity_id,
        'operator_id' => $batch->operator_id,
        'protocol_id' => $protocol->id,
        'return_type' => 'batch_return',
        'status'      => TissReturnStatus::Parsed->value,
        'received_at' => now(),
        'summary'     => $summary,
    ]);
}

/**
 * Envia e confirma o lote (1ª requisição = aceite) e o deixa como uma rejeição por
 * retorno antiga o deixaria: protocolo rejeitado, lote em "erro", guias negadas.
 *
 * @param array{batch: TissBatch, guides: list<TissGuide>} $f
 */
function trdAcknowledgeThenReject(array $f): TissProtocol
{
    app(SendTissBatchService::class)->send($f['batch']->fresh());

    $protocol = TissProtocol::query()->sole();
    $protocol->update(['status' => TissProtocolStatus::Rejected->value]);

    $f['batch']->update(['status' => TissBatchStatus::Error->value, 'last_error' => 'Lote rejeitado em retorno da operadora.']);
    $f['batch']->guides()->update(['status' => TissGuideStatus::Denied->value]);

    return $protocol;
}

/**
 * Transporte HTTP cuja 1ª requisição é o aceite e cuja 2ª — o reenvio — processa
 * $duringResend (o retorno que "chega" enquanto a requisição está em voo) e só
 * então responde com $secondResponse ('ack' | 'ack_no_status' | status HTTP de erro).
 */
function trdTransport(Closure $duringResend, int|string $secondResponse): void
{
    $calls = 0;

    Http::fake(['*' => function () use (&$calls, $duringResend, $secondResponse) {
        $calls++;

        if ($calls === 1) {
            return Http::response(TRD_ACK_BODY, 200);
        }

        $duringResend();

        return match ($secondResponse) {
            'ack'           => Http::response(TRD_ACK_BODY, 200),
            'ack_no_status' => Http::response(TRD_ACK_NO_STATUS, 200),
            default         => Http::response('resposta da operadora', (int) $secondResponse),
        };
    }]);
}

beforeEach(function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Retorno ACEITO comitado durante o voo do reenvio forçado
// ─────────────────────────────────────────────────────────────────────────────

it('[CORRIDA] retorno aceito processado durante o voo do reenvio forçado + o reenvio FALHA (401...): lote processado, guias pagas e protocolo aceito não são sobrescritos; o lote NÃO volta a ser editável', function (int $status) {
    $f      = trdFixture();
    $return = null;

    trdTransport(function () use (&$return) {
        app(ProcessTissReturnService::class)->process($return);
    }, $status);

    $protocol  = trdAcknowledgeThenReject($f);
    $return    = trdReturn($f['batch'], $protocol, ['status' => 'accepted']);
    $submitted = $f['batch']->fresh()->submitted_at;

    expect(fn () => app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true))
        ->toThrow(RuntimeException::class, __('tiss_send.forced_resend_failed', ['reason' => 'resposta da operadora']));

    $transmission = TissTransmission::query()->sole();

    expect($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and($f['batch']->fresh()->last_error)->toBeNull()
        ->and($f['batch']->fresh()->submitted_at->equalTo($submitted))->toBeTrue()
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Paid])
        ->and($protocol->fresh()->status)->toBe(TissProtocolStatus::Accepted)
        ->and($return->fresh()->status)->toBe(TissReturnStatus::Processed)
        ->and($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($transmission->attempt)->toBe(2)
        ->and($transmission->metadata['forced_resend']['last_failure']['http_status'])->toBe($status)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
})->with([401, 403, 422, 500]);

it('[CORRIDA] retorno "em processamento" durante o voo (lote confirmado, guias confirmadas) + reenvio que falha: o status confirmado do retorno é preservado', function () {
    $f      = trdFixture();
    $return = null;

    trdTransport(function () use (&$return) {
        app(ProcessTissReturnService::class)->process($return);
    }, 401);

    $protocol = trdAcknowledgeThenReject($f);
    $return   = trdReturn($f['batch'], $protocol, ['status' => 'processing']);

    expect(fn () => app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true))->toThrow(RuntimeException::class);

    expect($f['batch']->fresh()->status)->toBe(TissBatchStatus::Acknowledged)
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Acknowledged])
        ->and($protocol->fresh()->status)->toBe(TissProtocolStatus::Processing);
});

it('[CORRIDA] retorno aceito com glosa durante o voo + reenvio que falha: lote parcialmente pago e a guia glosada continuam como o retorno os deixou', function () {
    $f      = trdFixture();
    $return = null;

    trdTransport(function () use (&$return) {
        app(ProcessTissReturnService::class)->process($return);
    }, 401);

    $protocol = trdAcknowledgeThenReject($f);
    $return   = trdReturn($f['batch'], $protocol, ['status' => 'accepted', 'glosas' => [
        ['code' => '3010', 'description' => 'Glosa teste', 'amount' => 50.0, 'guide_number_provider' => 'GUI-0001'],
    ]]);

    expect(fn () => app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true))->toThrow(RuntimeException::class);

    expect($f['batch']->fresh()->status)->toBe(TissBatchStatus::PartiallyPaid)
        ->and($f['guides'][0]->fresh()->status)->toBe(TissGuideStatus::PartiallyDenied)
        ->and((float) $f['guides'][0]->fresh()->denied_amount)->toBe(50.0)
        ->and($f['guides'][1]->fresh()->status)->toBe(TissGuideStatus::Paid);
});

it('[CORRIDA] retorno aceito durante o voo + o reenvio DÁ CERTO: não rebaixa lote nem guias para "enviado" nem o protocolo para "recebido"', function () {
    $f      = trdFixture();
    $return = null;

    trdTransport(function () use (&$return) {
        app(ProcessTissReturnService::class)->process($return);
    }, 'ack_no_status');

    $protocol  = trdAcknowledgeThenReject($f);
    $return    = trdReturn($f['batch'], $protocol, ['status' => 'accepted']);
    $submitted = $f['batch']->fresh()->submitted_at;

    $second = app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true);

    expect($second->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($second->attempt)->toBe(2)
        ->and($second->response_payload)->toBe(TRD_ACK_NO_STATUS)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and($f['batch']->fresh()->submitted_at->equalTo($submitted))->toBeTrue()
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Paid])
        ->and($protocol->fresh()->status)->toBe(TissProtocolStatus::Accepted)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

it('sem retorno no meio do voo nada muda: o reenvio forçado de um lote rejeitado por retorno continua levando a "enviado" (sucesso) ou a "erro" (falha)', function (int|string $second, TissBatchStatus $expected) {
    $f = trdFixture();

    trdTransport(fn () => null, $second);

    trdAcknowledgeThenReject($f);

    try {
        app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true);
    } catch (RuntimeException) {
        // esperado nos casos de falha
    }

    expect($f['batch']->fresh()->status)->toBe($expected);
})->with([
    'sucesso' => ['ack', TissBatchStatus::Sent],
    'falha'   => [401, TissBatchStatus::Error],
]);

it('retorno REJEITADO durante o voo: o lote segue em "erro" (já era o desfecho) e um reenvio que dá certo o leva a "enviado"', function () {
    $f      = trdFixture();
    $return = null;

    trdTransport(function () use (&$return) {
        app(ProcessTissReturnService::class)->process($return);
    }, 'ack');

    $protocol = trdAcknowledgeThenReject($f);
    $return   = trdReturn($f['batch'], $protocol, ['status' => 'rejected']);

    app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true);

    expect($return->fresh()->status)->toBe(TissReturnStatus::Processed)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Sent);
});

// ─────────────────────────────────────────────────────────────────────────────
// Lote entregue por RETORNO depois de uma entrega em dúvida: o reenvio não o rebaixa
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Entrega em dúvida (resposta perdida: transmissão "timeout", lote "enviando") que
 * um retorno da operadora depois confirma: protocolo ligado ao lote, retorno
 * aceito processado — lote processado, guias pagas.
 *
 * Http::fake() ACUMULA stubs (o 1º que casa responde), então o transporte lê o que
 * responder de $transport['next'] e conta as requisições em $transport['calls'].
 *
 * @param array{batch: TissBatch, guides: list<TissGuide>} $f
 *
 * @return array{protocol: TissProtocol, return: TissReturn}
 */
function trdDoubtThenReturn(array $f, ArrayObject $transport): array
{
    Http::fake(['*' => function () use ($transport) {
        $transport['calls']++;

        if ($transport['calls'] === 1) {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
        }

        return match ($transport['next']) {
            'ack'           => Http::response(TRD_ACK_BODY, 200),
            'ack_no_status' => Http::response(TRD_ACK_NO_STATUS, 200),
            default         => Http::response('resposta da operadora', (int) $transport['next']),
        };
    }]);

    try {
        app(SendTissBatchService::class)->send($f['batch']->fresh());
    } catch (RuntimeException) {
        // dúvida: esperado
    }

    $protocol = TissProtocol::query()->create([
        'entity_id'       => $f['batch']->entity_id,
        'operator_id'     => $f['batch']->operator_id,
        'batch_id'        => $f['batch']->id,
        'protocol_number' => 'PRT-DUVIDA',
        'status'          => TissProtocolStatus::Received->value,
    ]);

    $return = trdReturn($f['batch'], $protocol, ['status' => 'accepted']);

    app(ProcessTissReturnService::class)->process($return);

    return ['protocol' => $protocol, 'return' => $return];
}

it('[CORRIDA] entrega em dúvida confirmada depois por um RETORNO: o envio normal não retoma nem rebaixa o lote processado — nenhuma requisição sai', function () {
    $f         = trdFixture();
    $transport = new ArrayObject(['calls' => 0, 'next' => 'ack']);

    $d = trdDoubtThenReturn($f, $transport);

    expect($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($transport['calls'])->toBe(1);

    $result = app(SendTissBatchService::class)->send($f['batch']->fresh());

    expect($transport['calls'])->toBe(1) // nenhuma requisição saiu
        ->and($result->id)->toBe(TissTransmission::query()->sole()->id)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Paid])
        ->and($d['protocol']->fresh()->status)->toBe(TissProtocolStatus::Accepted);
});

it('[CORRIDA] o mesmo lote entregue por retorno + reenvio FORÇADO que dá certo: lote e guias seguem processados/pagos; a transmissão passa a confirmada e a chave a concluída', function () {
    $f         = trdFixture();
    $transport = new ArrayObject(['calls' => 0, 'next' => 'ack']);

    trdDoubtThenReturn($f, $transport);

    $transport['next'] = 'ack_no_status';

    $result = app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true);

    expect($result->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($result->attempt)->toBe(2)
        ->and($transport['calls'])->toBe(2)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Paid])
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

it('[CORRIDA] o mesmo lote entregue por retorno + reenvio FORÇADO que falha: lote e guias intactos; a falha fica registrada à parte e a mensagem diz que a confirmação foi preservada', function () {
    $f         = trdFixture();
    $transport = new ArrayObject(['calls' => 0, 'next' => 'ack']);

    trdDoubtThenReturn($f, $transport);

    $transport['next'] = 401;

    expect(fn () => app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true))
        ->toThrow(RuntimeException::class, __('tiss_send.forced_resend_failed', ['reason' => 'resposta da operadora']));

    $transmission = TissTransmission::query()->sole();

    expect($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Paid])
        ->and($transmission->status)->toBe(TissTransmissionStatus::Timeout) // sem aceite HTTP, o desfecho do 1º envio segue desconhecido
        ->and($transmission->metadata['forced_resend']['last_failure']['error_code'])->toBe('http_401')
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');
});

it('tentativa interrompida (sending com o lease vencido) cujo lote um retorno já entregou: encerrada uma vez só, sem retomar o envio nem repetir o aviso', function (bool $hadProtocol, TissTransmissionStatus $settled) {
    $f = trdFixture();

    // Worker morto no meio do envio: linha "sending" velha, lote "enviando".
    $xml = $f['batch']->xmlDocument;
    TissTransmission::query()->create([
        'entity_id'       => $f['batch']->entity_id,
        'operator_id'     => $f['batch']->operator_id,
        'batch_id'        => $f['batch']->id,
        'xml_document_id' => $xml->id,
        'idempotency_key' => hash('sha256', implode('|', [(string) $f['batch']->entity_id, (string) $f['batch']->id, (string) $xml->file_hash])),
        'status'          => TissTransmissionStatus::Sending->value,
        'queue'           => 'tiss-send',
        'attempt'         => 1,
        'max_attempts'    => 5,
        'requested_at'    => now()->subSeconds((int) config('tiss.transport.lease_seconds') + 60),
    ]);
    $f['batch']->update(['status' => TissBatchStatus::Processed->value]); // um retorno o entregou depois

    if ($hadProtocol) {
        $protocol = TissProtocol::query()->create([
            'entity_id'       => $f['batch']->entity_id,
            'operator_id'     => $f['batch']->operator_id,
            'batch_id'        => $f['batch']->id,
            'protocol_number' => 'PRT-ANTIGO',
            'status'          => TissProtocolStatus::Accepted->value,
        ]);
        TissTransmission::query()->sole()->update(['protocol_id' => $protocol->id]);
    }

    Http::fake();

    app(SendTissBatchService::class)->send($f['batch']->fresh());
    app(SendTissBatchService::class)->send($f['batch']->fresh());

    Http::assertNothingSent();

    $transmission = TissTransmission::query()->sole();

    expect($transmission->status)->toBe($settled)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and(TissStatusHistory::query()->where('context_id', $transmission->id)->where('reason', 'like', '%Tentativa interrompida encerrada%')->count())->toBe(1);
})->with([
    'sem aceite anterior (desfecho do HTTP desconhecido)' => [false, TissTransmissionStatus::Timeout],
    'já tinha protocolo de um aceite anterior'            => [true, TissTransmissionStatus::Acknowledged],
]);
