<?php

declare(strict_types=1);

use App\Rules\CpfCnpj;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * CPF (11) e CNPJ (14, inclusive alfanumérico — IN RFB 2.229/2024) com dígitos verificadores.
 * Os alfanuméricos válidos abaixo foram aceitos pelo sandbox do Asaas (e o de DV errado, recusado:
 * "O CPF/CNPJ informado é inválido.").
 */
it('aceita CPF válido, com ou sem máscara', function (string $value) {
    expect(CpfCnpj::isValid($value))->toBeTrue();
})->with([
    '52998224725',
    '529.982.247-25',
    '11144477735',
    ' 111.444.777-35 ',
]);

it('aceita CNPJ numérico válido, com ou sem máscara', function (string $value) {
    expect(CpfCnpj::isValid($value))->toBeTrue();
})->with([
    '11222333000181',
    '11.222.333/0001-81',
    '00000000000191',
]);

it('aceita CNPJ alfanumérico válido (letras nas 12 primeiras posições), com máscara e em minúsculas', function (string $value) {
    expect(CpfCnpj::isValid($value))->toBeTrue();
})->with([
    'HCHGXN73000170',
    '86DC7ITW000105',
    'HC.HGX.N73/0001-70',
    'hchgxn73000170',
    '12ABC34501DE35',
]);

it('recusa CPF com dígito verificador errado', function (string $value) {
    expect(CpfCnpj::isValid($value))->toBeFalse();
})->with([
    '52998224724', // DV2
    '52998224715', // DV1
    '11144477736',
    '12345678900',
]);

it('recusa CNPJ com dígito verificador errado — numérico e alfanumérico', function (string $value) {
    expect(CpfCnpj::isValid($value))->toBeFalse();
})->with([
    '11222333000182',
    '11222333000191',
    'HCHGXN73000171',
    'IXBSVWDU000100', // o Asaas recusou: DV errado
    '12ABC34501DE36',
    '12ABC34501DE55',
]);

it('recusa todos os caracteres iguais (a Receita não emite)', function (string $value) {
    expect(CpfCnpj::isValid($value))->toBeFalse();
})->with([
    '00000000000',
    '11111111111',
    '99999999999',
    '00000000000000',
    '11111111111111',
]);

it('recusa tamanho errado, letra no CPF e letra nos dígitos verificadores do CNPJ', function (string $value) {
    expect(CpfCnpj::isValid($value))->toBeFalse();
})->with([
    '5299822472',        // 10
    '529982247250',      // 12
    '1122233300018',     // 13
    '112223330001811',   // 15
    '5299822472A',       // letra no CPF
    '11222333000AB1',    // letra nos DVs do CNPJ
    'ABCDEFGHIJKLMN',
    '123',
]);

it('recusa vazio, só pontuação e valor que não é texto/número', function (mixed $value) {
    expect(CpfCnpj::isValid($value))->toBeFalse();
})->with([
    null,
    '',
    '   ',
    '..-/',
    [[]],
    [['52998224725']],
    true,
    false,
]);

it('aceita o número inteiro quando o JSON manda o documento sem aspas', function () {
    expect(CpfCnpj::isValid(52998224725))->toBeTrue()
        ->and(CpfCnpj::isValid(52998224724))->toBeFalse();
});

it('como regra de validação: passa o válido e falha o inválido com a mensagem traduzida', function () {
    app()->setLocale('pt_BR');

    $ok  = Validator::make(['doc' => '529.982.247-25'], ['doc' => [new CpfCnpj()]]);
    $bad = Validator::make(['doc' => '529.982.247-24'], ['doc' => [new CpfCnpj()]]);

    expect($ok->passes())->toBeTrue()
        ->and($bad->fails())->toBeTrue()
        ->and($bad->errors()->first('doc'))->toBe(__('validation.cpf_cnpj_invalid'))
        ->and($bad->errors()->first('doc'))->toContain('CPF ou CNPJ');

    app()->setLocale('en');

    expect(Validator::make(['doc' => '529.982.247-24'], ['doc' => [new CpfCnpj()]])->errors()->first('doc'))
        ->toBe(__('validation.cpf_cnpj_invalid'))
        ->and(__('validation.cpf_cnpj_invalid'))->toContain('not valid');
});

it('vazio com nullable não chama a regra (a obrigatoriedade é de quem a usa)', function () {
    expect(Validator::make(['doc' => null], ['doc' => ['nullable', new CpfCnpj()]])->passes())->toBeTrue()
        ->and(Validator::make(['doc' => ''], ['doc' => ['nullable', new CpfCnpj()]])->passes())->toBeTrue()
        ->and(Validator::make(['doc' => null], ['doc' => ['required', new CpfCnpj()]])->fails())->toBeTrue();
});
