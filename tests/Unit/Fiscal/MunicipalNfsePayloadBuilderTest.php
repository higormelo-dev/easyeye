<?php

use App\Services\Fiscal\Payloads\MunicipalNfsePayloadBuilder;
use Carbon\CarbonImmutable;

require_once __DIR__ . '/Support/NfseContextFixture.php';

function municipalPayload(array $context = []): array
{
    return (new MunicipalNfsePayloadBuilder())->build(nfseContext($context));
}

/** Todas as chaves (com caminho) de um array aninhado, para comparar estrutura sem comparar valor. */
function nfseKeyPaths(array $data, string $prefix = ''): array
{
    $paths = [];

    foreach ($data as $key => $value) {
        $path    = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $paths[] = $path;

        if (is_array($value)) {
            $paths = array_merge($paths, nfseKeyPaths($value, $path));
        }
    }

    return $paths;
}

it('monta o payload municipal igual ao arquivo golden', function () {
    expect(municipalPayload())->toEqual(nfseFixture('nfse-municipal-golden'));
});

it('cobre toda chave do exemplo oficial da Focus (menos a que o schema chama de outro nome)', function () {
    $official = nfseFixture('focus-nfse-municipal-official');

    // O exemplo da Focus usa `codigo_tributacao_municipio`, mas o schema NFSeRequest (a referência) chama o campo de
    // `codigo_tributario_municipio` — o builder segue o schema.
    $official['servico']['codigo_tributario_municipio'] = $official['servico']['codigo_tributacao_municipio'];
    unset($official['servico']['codigo_tributacao_municipio']);

    $missing = array_diff(nfseKeyPaths($official), nfseKeyPaths(municipalPayload()));

    expect($missing)->toBe([]);
});

it('respeita os tipos do schema (texto nos códigos, número nos valores, booleano nas flags)', function () {
    $payload = municipalPayload();

    expect($payload['servico']['valor_servicos'])->toBeFloat()
        ->and($payload['servico']['aliquota'])->toBeFloat()
        ->and($payload['servico']['iss_retido'])->toBeBool()
        ->and($payload['optante_simples_nacional'])->toBeBool()
        ->and($payload['natureza_operacao'])->toBeString()
        ->and($payload['prestador']['codigo_municipio'])->toBeString()
        ->and($payload['tomador']['endereco']['cep'])->toBeString();
});

it('manda a data com o fuso do contexto (ISO 8601)', function () {
    expect(municipalPayload()['data_emissao'])->toBe('2026-10-18T14:30:00-03:00')
        ->and(municipalPayload(['issuedAt' => CarbonImmutable::parse('2026-10-18 17:30:00', 'UTC')])['data_emissao'])->toBe('2026-10-18T17:30:00+00:00');
});

it('usa CPF no lugar do CNPJ quando o tomador é pessoa física, nunca os dois', function () {
    $tomador = municipalPayload(['payer' => nfsePayer(['document' => '52998224725', 'document_type' => 'cpf'])])['tomador'];

    expect($tomador)->toHaveKey('cpf', '52998224725')
        ->and($tomador)->not->toHaveKey('cnpj');
});

it('preserva o CNPJ alfanumérico em maiúsculas (IN RFB 2.229/2024)', function () {
    $payload = municipalPayload([
        'prestador' => nfsePrestador(['cnpj' => '12.abc.345/01de-35']),
        'payer'     => nfsePayer(['document' => '12abc34501de35']),
    ]);

    expect($payload['prestador']['cnpj'])->toBe('12ABC34501DE35')
        ->and($payload['tomador']['cnpj'])->toBe('12ABC34501DE35');
});

it('omite o que não existe, sem string vazia nem null', function () {
    $payload = municipalPayload([
        'prestador' => nfsePrestador(['cnae' => '', 'codigo_tributario_municipio' => '', 'aliquota_iss' => 0.0]),
        'payer'     => nfsePayer(['email' => null, 'phone' => null, 'address' => ['logradouro' => null, 'numero' => '', 'complemento' => null, 'bairro' => null, 'cep' => '', 'uf' => '', 'codigo_municipio' => '']]),
    ]);

    expect($payload['tomador'])->not->toHaveKeys(['email', 'telefone', 'endereco'])
        ->and($payload['servico'])->not->toHaveKeys(['codigo_cnae', 'codigo_tributario_municipio', 'aliquota', 'base_calculo'])
        ->and($payload)->not->toHaveKey('regime_especial_tributacao');

    array_walk_recursive($payload, function ($value) {
        expect($value)->not->toBeNull()->and($value)->not->toBe('');
    });
});

it('omite o endereço inteiro quando o tomador não tem nenhum campo', function () {
    $payer = nfsePayer();
    unset($payer['address']);

    expect(municipalPayload(['payer' => $payer])['tomador'])->not->toHaveKey('endereco');
});

it('aplica os limites de tamanho da API', function () {
    $tomador = municipalPayload(['payer' => nfsePayer([
        'name'    => str_repeat('N', 200),
        'email'   => str_repeat('e', 90) . '@x.test',
        'address' => [
            'logradouro'  => str_repeat('L', 200),
            'numero'      => str_repeat('9', 20),
            'complemento' => str_repeat('C', 100),
            'bairro'      => str_repeat('B', 100),
        ],
    ])])['tomador'];

    expect(mb_strlen($tomador['razao_social']))->toBe(115)
        ->and(mb_strlen($tomador['email']))->toBe(80)
        ->and(mb_strlen($tomador['endereco']['logradouro']))->toBe(125)
        ->and(mb_strlen($tomador['endereco']['numero']))->toBe(10)
        ->and(mb_strlen($tomador['endereco']['complemento']))->toBe(60)
        ->and(mb_strlen($tomador['endereco']['bairro']))->toBe(60);
});

it('corta por caractere, não por byte (acentos)', function () {
    $tomador = municipalPayload(['payer' => nfsePayer(['name' => str_repeat('Ã', 130)])])['tomador'];

    expect(mb_strlen($tomador['razao_social']))->toBe(115)
        ->and(mb_check_encoding($tomador['razao_social'], 'UTF-8'))->toBeTrue();
});

it('normaliza telefone, CEP, UF e IBGE, e descarta o que não tem o formato', function () {
    $good = municipalPayload(['payer' => nfsePayer(['phone' => '(11) 99999-8888', 'address' => ['cep' => '01310-100', 'uf' => 'sp', 'codigo_municipio' => '3550308']])])['tomador'];

    expect($good['telefone'])->toBe('11999998888')
        ->and($good['endereco'])->toMatchArray(['cep' => '01310100', 'uf' => 'SP', 'codigo_municipio' => '3550308']);

    $bad = municipalPayload(['payer' => nfsePayer(['phone' => '99998888', 'address' => ['cep' => '0131', 'uf' => 'SAO', 'codigo_municipio' => '355030']])])['tomador'];

    expect($bad)->not->toHaveKey('telefone')
        ->and($bad['endereco'])->not->toHaveKeys(['cep', 'uf', 'codigo_municipio']);
});

it('manda a alíquota como fração e a base de cálculo igual ao valor', function () {
    $servico = municipalPayload(['amount' => 1500.0, 'prestador' => nfsePrestador(['aliquota_iss' => 0.0275])])['servico'];

    expect($servico['aliquota'])->toBe(0.0275)
        ->and($servico['base_calculo'])->toBe(1500.0)
        ->and($servico['valor_servicos'])->toBe(1500.0);
});

it('arredonda o valor a centavos e a alíquota a 4 casas', function () {
    $servico = municipalPayload(['amount' => 99.999, 'prestador' => nfsePrestador(['aliquota_iss' => 0.028749])])['servico'];

    expect($servico['valor_servicos'])->toBe(100.0)
        ->and($servico['aliquota'])->toBe(0.0287);
});

it('usa o município do PRESTADOR como local da prestação', function () {
    $payload = municipalPayload([
        'prestador' => nfsePrestador(['codigo_municipio' => '4106902']),
        'payer'     => nfsePayer(['address' => ['codigo_municipio' => '3304557']]),
    ]);

    expect($payload['servico']['codigo_municipio'])->toBe('4106902')
        ->and($payload['prestador']['codigo_municipio'])->toBe('4106902')
        ->and($payload['tomador']['endereco']['codigo_municipio'])->toBe('3304557');
});

it('valida natureza da operação e regime especial (valor fora da tabela vira o padrão / some)', function () {
    expect(municipalPayload(['prestador' => nfsePrestador(['natureza_operacao' => '9'])])['natureza_operacao'])->toBe('1')
        ->and(municipalPayload(['prestador' => nfsePrestador(['natureza_operacao' => '3'])])['natureza_operacao'])->toBe('3')
        ->and(municipalPayload(['prestador' => nfsePrestador(['regime_especial_tributacao' => '6'])])['regime_especial_tributacao'])->toBe('6')
        ->and(municipalPayload(['prestador' => nfsePrestador(['regime_especial_tributacao' => '0'])]))->not->toHaveKey('regime_especial_tributacao')
        ->and(municipalPayload(['prestador' => nfsePrestador(['regime_especial_tributacao' => '7'])]))->not->toHaveKey('regime_especial_tributacao');
});

it('só leva CNAE de 7 dígitos', function () {
    expect(municipalPayload(['prestador' => nfsePrestador(['cnae' => '6201-5/01'])])['servico']['codigo_cnae'])->toBe('6201501')
        ->and(municipalPayload(['prestador' => nfsePrestador(['cnae' => '62015'])])['servico'])->not->toHaveKey('codigo_cnae');
});

it('é determinístico e nunca leva segredo no corpo', function () {
    expect(municipalPayload())->toBe(municipalPayload());

    $json = strtolower((string) json_encode(municipalPayload()));

    foreach (['token', 'secret', 'authorization', 'password'] as $word) {
        expect($json)->not->toContain($word);
    }
});

it('corta a discriminação no teto configurável (padrão 2000), com trim', function () {
    expect(mb_strlen(municipalPayload(['description' => str_repeat('D', 2500)])['servico']['discriminacao']))->toBe(2000)
        ->and(municipalPayload(['description' => '  ' . str_repeat('D', 1500) . '  '])['servico']['discriminacao'])->toBe(str_repeat('D', 1500));

    expect(mb_strlen(municipalPayload(['description' => str_repeat('D', 500), 'descriptionLimit' => 120])['servico']['discriminacao']))->toBe(120);
});

it('devolve a discriminação exatamente como foi montada', function () {
    expect(municipalPayload(['description' => 'Texto livre — com acentos'])['servico']['discriminacao'])->toBe('Texto livre — com acentos');
});
