<?php

use App\Enums\Fiscal\NfseStandard;
use App\Services\Fiscal\Payloads\{MunicipalNfsePayloadBuilder, NationalNfsePayloadBuilder, NfsePayloadFactory};
use App\Support\Fiscal\NfsePayloadSanitizer;

require_once __DIR__ . '/Support/NfseContextFixture.php';

it('remove chaves de segredo em qualquer profundidade, sem diferenciar maiúsculas', function () {
    $clean = NfsePayloadSanitizer::clean([
        'status'               => 'autorizado',
        'token'                => 'abc',
        'Authorization'        => 'Basic xyz',
        'nested'               => ['api_key' => 'k', 'ApiKey' => 'k2', 'keep' => 1, 'deeper' => ['Password' => 'p', 'senha' => 's', 'ok' => true]],
        'webhook_secret'       => 's',
        'authorization_header' => 'X-Foo',
        'access_token'         => 't',
    ]);

    expect($clean)->toBe(['status' => 'autorizado', 'nested' => ['keep' => 1, 'deeper' => ['ok' => true]]]);
});

it('mantém listas e chaves numéricas na ordem', function () {
    $clean = NfsePayloadSanitizer::clean(['erros' => [['codigo' => 'E1', 'mensagem' => 'm'], ['codigo' => 'E2', 'token' => 'x']], 3 => 'c']);

    expect($clean)->toBe(['erros' => [['codigo' => 'E1', 'mensagem' => 'm'], ['codigo' => 'E2']], 3 => 'c']);
});

it('não mexe nos campos da nota (url, caminhos, números)', function () {
    $response = [
        'status'                  => 'autorizado', 'numero' => '1', 'codigo_verificacao' => 'ABC123', 'url' => 'https://x.test/n.html',
        'caminho_xml_nota_fiscal' => '/arquivos/1/n.xml', 'url_danfse' => 'https://x.test/n.pdf', 'protocolo' => 'P1',
    ];

    expect(NfsePayloadSanitizer::clean($response))->toBe($response);
});

it('escolhe o builder pelo padrão da nota', function () {
    $factory = new NfsePayloadFactory();

    expect($factory->for(NfseStandard::Municipal))->toBeInstanceOf(MunicipalNfsePayloadBuilder::class)
        ->and($factory->for(NfseStandard::National))->toBeInstanceOf(NationalNfsePayloadBuilder::class);
});

it('o payload montado pelos dois builders passa limpo pelo sanitizador (sem chave de segredo para remover)', function () {
    foreach ([NfseStandard::Municipal, NfseStandard::National] as $standard) {
        $payload = (new NfsePayloadFactory())->for($standard)->build(nfseContext());

        expect(NfsePayloadSanitizer::clean($payload))->toBe($payload);
    }
});
