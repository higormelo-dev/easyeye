<?php

use App\Services\Fiscal\Payloads\NationalNfsePayloadBuilder;
use Carbon\CarbonImmutable;

require_once __DIR__ . '/Support/NfseContextFixture.php';

function nationalPayload(array $context = []): array
{
    return (new NationalNfsePayloadBuilder())->build(nfseContext($context));
}

it('monta o payload nacional igual ao arquivo golden', function () {
    expect(nationalPayload())->toEqual(nfseFixture('nfse-nacional-golden'));
});

it('cobre os campos do exemplo oficial da Focus e respeita os tipos dele', function () {
    $official = nfseFixture('focus-dps-nacional-official');
    $payload  = nationalPayload();

    // O exemplo oficial traz NIF (tomador/prestador estrangeiro) e CNPJ *e* CPF do tomador juntos: são do exemplo,
    // não do fluxo — a DPS do SaaS nunca tem NIF e o tomador é uma pessoa ou outra.
    $notSent = ['nif_prestador', 'nif_tomador', 'cpf_tomador'];

    foreach (array_diff(array_keys($official), $notSent) as $key) {
        expect($payload)->toHaveKey($key);

        // Mesmo tipo JSON do exemplo: inteiro onde ele usa inteiro, texto onde usa texto. O valor do serviço é decimal
        // (o exemplo usa 1 só porque o valor de teste é redondo).
        if ($key === 'valor_servico') {
            expect($payload[$key])->toBeNumeric();
        } elseif (is_int($official[$key])) {
            expect($payload[$key])->toBeInt();
        } elseif (is_string($official[$key])) {
            expect($payload[$key])->toBeString();
        }
    }
});

it('manda os obrigatórios da DPS (lista oficial de campos)', function () {
    expect(nationalPayload())->toHaveKeys([
        'data_emissao', 'serie_dps', 'numero_dps', 'data_competencia', 'emitente_dps', 'codigo_municipio_emissora',
        'cnpj_prestador', 'codigo_opcao_simples_nacional', 'regime_especial_tributacao', 'cnpj_tomador',
        'codigo_municipio_prestacao', 'codigo_tributacao_nacional_iss', 'descricao_servico', 'valor_servico', 'tributacao_iss',
    ]);
});

it('recusa montar a DPS sem série e número', function () {
    expect(fn () => nationalPayload(['dpsSeries' => null, 'dpsNumber' => null]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => nationalPayload(['dpsNumber' => null]))->toThrow(InvalidArgumentException::class);
});

it('manda a data de emissão em UTC mesmo com o contexto em horário de Brasília', function () {
    $payload = nationalPayload(['issuedAt' => CarbonImmutable::parse('2026-10-18 23:30:00', 'America/Sao_Paulo')]);

    expect($payload['data_emissao'])->toBe('2026-10-19T02:30:00+00:00')
        ->and($payload['data_competencia'])->toBe('2026-10-01');
});

it('deriva o código do Simples Nacional da DPS: 1 não optante, 2 MEI (optante + regime 5), 3 ME/EPP', function () {
    $opcao = fn (array $prestador) => nationalPayload(['prestador' => nfsePrestador($prestador)]);

    expect($opcao(['optante_simples_nacional' => false])['codigo_opcao_simples_nacional'])->toBe(1)
        ->and($opcao(['optante_simples_nacional' => false, 'regime_especial_tributacao' => '5'])['codigo_opcao_simples_nacional'])->toBe(1)
        ->and($opcao(['optante_simples_nacional' => true, 'regime_especial_tributacao' => null])['codigo_opcao_simples_nacional'])->toBe(3)
        ->and($opcao(['optante_simples_nacional' => true, 'regime_especial_tributacao' => '6'])['codigo_opcao_simples_nacional'])->toBe(3)
        ->and($opcao(['optante_simples_nacional' => true, 'regime_especial_tributacao' => '5'])['codigo_opcao_simples_nacional'])->toBe(2);
});

it('MEI não leva o "5" para o regime especial do nacional (lá 5 é profissional autônomo): vai 0', function () {
    $payload = nationalPayload(['prestador' => nfsePrestador(['optante_simples_nacional' => true, 'regime_especial_tributacao' => '5'])]);

    expect($payload['codigo_opcao_simples_nacional'])->toBe(2)->and($payload['regime_especial_tributacao'])->toBe(0);
});

it('usa a tabela do regime especial do NACIONAL (0 a 6 e 9; fora disso, 0)', function () {
    foreach (['0' => 0, '3' => 3, '6' => 6, '9' => 9, '7' => 0, '8' => 0, 'x' => 0, '' => 0] as $configured => $sent) {
        expect(nationalPayload(['prestador' => nfsePrestador(['regime_especial_tributacao' => $configured])])['regime_especial_tributacao'])->toBe($sent);
    }

    expect(nationalPayload(['prestador' => nfsePrestador(['regime_especial_tributacao' => null])])['regime_especial_tributacao'])->toBe(0);
});

it('manda o ISS em PERCENTUAL e a retenção pelo código do nacional', function () {
    $payload = nationalPayload(['prestador' => nfsePrestador(['aliquota_iss' => 0.0275, 'iss_retido' => true])]);

    expect($payload['percentual_aliquota_relativa_municipio'])->toBe(2.75)
        ->and($payload['tipo_retencao_iss'])->toBe(2)
        ->and(nationalPayload()['tipo_retencao_iss'])->toBe(1);
});

it('não manda alíquota quando não há alíquota configurada', function () {
    expect(nationalPayload(['prestador' => nfsePrestador(['aliquota_iss' => 0.0])]))->not->toHaveKey('percentual_aliquota_relativa_municipio');
});

it('valida a tributação do ISS (1 a 4; padrão 1)', function () {
    foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 4, 0 => 1, 9 => 1] as $configured => $sent) {
        expect(nationalPayload(['prestador' => nfsePrestador(['tributacao_iss' => $configured])])['tributacao_iss'])->toBe($sent);
    }
});

it('limpa o código de tributação nacional para os 6 dígitos', function () {
    expect(nationalPayload(['prestador' => nfsePrestador(['codigo_tributacao_nacional_iss' => '01.07.01'])])['codigo_tributacao_nacional_iss'])->toBe('010701');
});

it('só manda o código tributário municipal quando ele tem os 3 dígitos do cTribMun', function () {
    expect(nationalPayload(['prestador' => nfsePrestador(['codigo_tributario_municipio' => '620'])]))->toHaveKey('codigo_tributacao_municipal_iss', '620')
        ->and(nationalPayload(['prestador' => nfsePrestador(['codigo_tributario_municipio' => '62015'])]))->not->toHaveKey('codigo_tributacao_municipal_iss')
        ->and(nationalPayload(['prestador' => nfsePrestador(['codigo_tributario_municipio' => ''])]))->not->toHaveKey('codigo_tributacao_municipal_iss');
});

it('usa CPF do tomador no lugar do CNPJ, nunca os dois', function () {
    $payload = nationalPayload(['payer' => nfsePayer(['document' => '52998224725', 'document_type' => 'cpf'])]);

    expect($payload)->toHaveKey('cpf_tomador', '52998224725')
        ->and($payload)->not->toHaveKey('cnpj_tomador');
});

it('preserva o CNPJ alfanumérico (IN RFB 2.229/2024) como texto', function () {
    $payload = nationalPayload([
        'prestador' => nfsePrestador(['cnpj' => '12ABC34501DE35']),
        'payer'     => nfsePayer(['document' => '12abc34501de35']),
    ]);

    expect($payload['cnpj_prestador'])->toBe('12ABC34501DE35')
        ->and($payload['cnpj_tomador'])->toBe('12ABC34501DE35');
});

it('manda só o essencial do tomador quando ele não tem endereço, e-mail nem telefone', function () {
    $payload = nationalPayload(['payer' => ['name' => 'CLINICA SEM ENDERECO', 'document' => '11444777000161', 'document_type' => 'cnpj']]);

    expect($payload)->toHaveKeys(['cnpj_tomador', 'razao_social_tomador'])
        ->and($payload)->not->toHaveKeys([
            'codigo_municipio_tomador', 'cep_tomador', 'logradouro_tomador', 'numero_tomador', 'complemento_tomador',
            'bairro_tomador', 'telefone_tomador', 'email_tomador',
        ]);
});

it('mantém CEP e telefone como texto (zero à esquerda não pode sumir)', function () {
    $payload = nationalPayload(['payer' => nfsePayer(['phone' => '0800123456', 'address' => ['cep' => '01310-100']])]);

    expect($payload['cep_tomador'])->toBe('01310100')
        ->and($payload['telefone_tomador'])->toBe('0800123456');
});

it('aplica os limites do padrão nacional', function () {
    $payload = nationalPayload([
        'description' => str_repeat('D', 1500),
        'payer'       => nfsePayer([
            'name'    => str_repeat('N', 300),
            'email'   => str_repeat('e', 90) . '@x.test',
            'address' => ['logradouro' => str_repeat('L', 300), 'numero' => str_repeat('9', 100), 'complemento' => str_repeat('C', 200), 'bairro' => str_repeat('B', 100)],
        ]),
    ]);

    expect(mb_strlen($payload['descricao_servico']))->toBe(1000)
        ->and(mb_strlen($payload['razao_social_tomador']))->toBe(150)
        ->and(mb_strlen($payload['email_tomador']))->toBe(80)
        ->and(mb_strlen($payload['logradouro_tomador']))->toBe(255)
        ->and(mb_strlen($payload['numero_tomador']))->toBe(60)
        ->and(mb_strlen($payload['complemento_tomador']))->toBe(156)
        ->and(mb_strlen($payload['bairro_tomador']))->toBe(60);
});

it('usa o município do prestador na emissora e na prestação', function () {
    $payload = nationalPayload(['prestador' => nfsePrestador(['codigo_municipio' => '4106902']), 'payer' => nfsePayer(['address' => ['codigo_municipio' => '3304557']])]);

    expect($payload['codigo_municipio_emissora'])->toBe(4106902)
        ->and($payload['codigo_municipio_prestacao'])->toBe(4106902)
        ->and($payload['codigo_municipio_tomador'])->toBe('3304557');
});

it('apara a descrição e corta nos 1000 da DPS por caractere', function () {
    $payload = nationalPayload(['description' => '  ' . str_repeat('Ã', 1100) . '  ']);

    expect(mb_strlen($payload['descricao_servico']))->toBe(1000)
        ->and($payload['descricao_servico'])->not->toStartWith(' ')
        ->and(nationalPayload(['description' => '  texto  '])['descricao_servico'])->toBe('texto');
});

it('arredonda o valor a centavos e é determinístico', function () {
    expect(nationalPayload(['amount' => 99.999])['valor_servico'])->toBe(100.0)
        ->and(nationalPayload())->toBe(nationalPayload());
});

it('não deixa o contador da DPS no corpo além de série e número, e nunca leva segredo', function () {
    $payload = nationalPayload();
    $json    = strtolower((string) json_encode($payload));

    expect($payload)->not->toHaveKey('proximo_numero_dps');

    foreach (['token', 'secret', 'authorization', 'password'] as $word) {
        expect($json)->not->toContain($word);
    }
});
