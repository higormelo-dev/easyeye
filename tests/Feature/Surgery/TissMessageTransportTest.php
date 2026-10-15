<?php

declare(strict_types=1);

use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, MockTissTransportClient, TissMessageTransportContract, TissTransportContract};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * M3b — transporte de mensagem avulsa: o driver escolhido vale para lote e mensagem, o HTTP manda
 * a transação e a guia nos cabeçalhos (e não mexe no envio de lote) e a falha vira "sem resposta".
 */

function stcCredential(): TissEntityOperatorCredential
{
    return new TissEntityOperatorCredential([
        'entity_id'           => '019bd111-0000-7000-8000-000000000001',
        'environment'         => 'production',
        'endpoint_submission' => 'https://operadora.test/tiss',
        'ssl_enabled'         => true,
    ]);
}

it('o driver configurado vale para o lote E para a mensagem avulsa', function () {
    config(['tiss.transport.driver' => 'mock']);
    expect(app(TissMessageTransportContract::class))->toBeInstanceOf(MockTissTransportClient::class)
        ->and(app(TissTransportContract::class))->toBeInstanceOf(MockTissTransportClient::class);

    config(['tiss.transport.driver' => 'http']);
    expect(app(TissMessageTransportContract::class))->toBeInstanceOf(HttpTissTransportClient::class)
        ->and(app(TissTransportContract::class))->toBeInstanceOf(HttpTissTransportClient::class);
});

it('HTTP: manda a transação, a guia e a chave de idempotência nos cabeçalhos e extrai o protocolo', function () {
    Http::fake(['operadora.test/*' => Http::response('<a><numeroProtocolo>PRT-77</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></a>', 200)]);

    $result = app(HttpTissTransportClient::class)->sendMessage(stcCredential(), '<xml/>', 'chave-1', 'SOLICITACAO_PROCEDIMENTOS', ['guide_number' => 'GUI-1']);

    expect($result['ok'])->toBeTrue()
        ->and($result['protocol_number'])->toBe('PRT-77')
        ->and($result['protocol_status'])->toBe('accepted');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Idempotency-Key', 'chave-1')
        && $request->hasHeader('X-Tiss-Transaction', 'SOLICITACAO_PROCEDIMENTOS')
        && $request->hasHeader('X-Guide-Number', 'GUI-1')
        && ! $request->hasHeader('X-Batch-Number')
        && $request->body() === '<xml/>');
});

it('HTTP: o envio de LOTE continua com X-Batch-Number e sem cabeçalhos da mensagem avulsa', function () {
    Http::fake(['operadora.test/*' => Http::response('<a/>', 200)]);

    app(HttpTissTransportClient::class)->sendBatch(stcCredential(), '<xml/>', 'chave-2', ['batch_number' => 'LOT-1']);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Batch-Number', 'LOT-1') && ! $request->hasHeader('X-Tiss-Transaction'));
});

it('HTTP: exceção de rede vira "sem resposta" (status nulo)', function () {
    Http::fake(['operadora.test/*' => fn () => throw new ConnectionException('cURL error 28')]);

    $down = app(HttpTissTransportClient::class)->sendMessage(stcCredential(), '<xml/>', 'k', 'ENVIO_ANEXO');

    expect($down)->toMatchArray(['ok' => false, 'status_code' => null, 'error_code' => 'transport_exception']);
});

it('HTTP: erro devolvido pela operadora volta com o status (veredito, não dúvida)', function () {
    Http::fake(['operadora.test/*' => Http::response('negado', 422)]);

    expect(app(HttpTissTransportClient::class)->sendMessage(stcCredential(), '<xml/>', 'k', 'ENVIO_ANEXO'))
        ->toMatchArray(['ok' => false, 'status_code' => 422, 'error_code' => 'http_422']);
});

it('HTTP: sem endpoint configurado nem sai da clínica (missing_endpoint)', function () {
    $credential                      = stcCredential();
    $credential->endpoint_submission = null;

    expect(app(HttpTissTransportClient::class)->sendMessage($credential, '<xml/>', 'k', 'ENVIO_ANEXO'))
        ->toMatchArray(['ok' => false, 'status_code' => null, 'error_code' => 'missing_endpoint']);
});

it('Mock: devolve recibo aceito com protocolo e, a pedido, a resposta de autorização', function () {
    $mock = new MockTissTransportClient();

    $plain = $mock->sendMessage(stcCredential(), '<xml/>', 'k', 'SOLICITACAO_PROCEDIMENTOS', ['protocol_number' => 'PRT-9']);

    expect($plain['ok'])->toBeTrue()
        ->and($plain['protocol_number'])->toBe('PRT-9')
        ->and($plain['response_body'])->toContain('<numeroProtocolo>PRT-9</numeroProtocolo>')
        ->and($plain['response_body'])->not->toContain('autorizacaoServicos');

    $with = $mock->sendMessage(stcCredential(), '<xml/>', 'k', 'SOLICITACAO_PROCEDIMENTOS', [
        'guide_number'       => 'GUI-5',
        'mock_authorization' => ['status' => '3', 'operator_guide_number' => '', 'password' => ''],
    ]);

    expect($with['response_body'])->toContain('<autorizacaoServicos>')->toContain('<statusSolicitacao>3</statusSolicitacao>');
});
