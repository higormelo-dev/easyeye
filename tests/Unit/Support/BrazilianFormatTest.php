<?php

use App\Support\BrazilianFormat;

it('formata CPF gravado só com dígitos', function () {
    expect(BrazilianFormat::cpf('12345678901'))->toBe('123.456.789-01')
        ->and(BrazilianFormat::cpf('123.456.789-01'))->toBe('123.456.789-01');
});

it('formata CNPJ e escolhe CPF/CNPJ pela quantidade de dígitos', function () {
    expect(BrazilianFormat::cnpj('12345678000190'))->toBe('12.345.678/0001-90')
        ->and(BrazilianFormat::cpfCnpj('12345678000190'))->toBe('12.345.678/0001-90')
        ->and(BrazilianFormat::cpfCnpj('12345678901'))->toBe('123.456.789-01');
});

it('formata telefone fixo e celular', function () {
    expect(BrazilianFormat::phone('6133334444'))->toBe('(61) 3333-4444')
        ->and(BrazilianFormat::phone('61999998888'))->toBe('(61) 99999-8888')
        ->and(BrazilianFormat::phone('(61) 99999-8888'))->toBe('(61) 99999-8888');
});

it('formata CEP', function () {
    expect(BrazilianFormat::cep('01310100'))->toBe('01310-100');
});

it('devolve null para vazio', function (?string $value) {
    expect(BrazilianFormat::cpf($value))->toBeNull()
        ->and(BrazilianFormat::phone($value))->toBeNull()
        ->and(BrazilianFormat::cep($value))->toBeNull();
})->with([null, '', '   ']);

it('não mutila valor com quantidade de dígitos inesperada', function () {
    expect(BrazilianFormat::phone('+1 555 0100'))->toBe('+1 555 0100')
        ->and(BrazilianFormat::cpf('123'))->toBe('123')
        ->and(BrazilianFormat::cep(' 0131 '))->toBe('0131');
});

it('formata CNPJ alfanumérico (IN RFB 2.229/2024)', function () {
    expect(BrazilianFormat::cnpj('12ABC34501DE35'))->toBe('12.ABC.345/01DE-35')
        ->and(BrazilianFormat::cnpj('12.abc.345/01de-35'))->toBe('12.ABC.345/01DE-35')
        ->and(BrazilianFormat::cpfCnpj('12ABC34501DE35'))->toBe('12.ABC.345/01DE-35')
        // DV com letra não é CNPJ válido: devolve como veio
        ->and(BrazilianFormat::cnpj('12ABC34501DEXY'))->toBe('12ABC34501DEXY');
});

it('formata telefone legado gravado com DDI sem confundir com DDD 55', function () {
    expect(BrazilianFormat::phone('5561999998888'))->toBe('(61) 99999-8888')
        ->and(BrazilianFormat::phone('556133334444'))->toBe('(61) 3333-4444')
        ->and(BrazilianFormat::phone('55999998888'))->toBe('(55) 99999-8888')
        ->and(BrazilianFormat::phone('5533334444'))->toBe('(55) 3333-4444');
});

it('formata números não geográficos (0800, 4004)', function () {
    expect(BrazilianFormat::phone('08001234567'))->toBe('0800 123 4567')
        ->and(BrazilianFormat::phone('40040001'))->toBe('4004-0001')
        ->and(BrazilianFormat::phone('30031234'))->toBe('3003-1234');
});

it('canonicalPhone normaliza para gravar: só dígitos, sem DDI, null quando vazio', function (?string $input, ?string $expected) {
    expect(BrazilianFormat::canonicalPhone($input))->toBe($expected);
})->with([
    'mascarado'          => ['(61) 99999-8888', '61999998888'],
    '+55 colado'         => ['+55 61 99999-8888', '61999998888'],
    'DDI só dígitos'     => ['556133334444', '6133334444'],
    'DDD 55 preservado'  => ['(55) 99999-8888', '55999998888'],
    '0800 preservado'    => ['0800 123 4567', '08001234567'],
    'estrangeiro'        => ['+351 912 345 678', '+351912345678'],
    'estrangeiro 11 díg' => ['+34 912 345 678', '+34912345678'],
    'sem dígitos'        => ['(', null],
    'null'               => [null, null],
]);

it('não formata número estrangeiro como brasileiro (DDI diferente de 55)', function (string $foreign) {
    expect(BrazilianFormat::phone($foreign))->toBe($foreign);
})->with(['+34 912 345 678', '+1 (305) 555-0100', '+33 1 23 45 67 89', '+34912345678']);

it('documentChars preserva letras do CNPJ alfanumérico e maiúsculas', function (?string $input, ?string $expected) {
    expect(BrazilianFormat::documentChars($input))->toBe($expected);
})->with([
    'CNPJ numérico'     => ['12.345.678/0001-90', '12345678000190'],
    'CNPJ alfanumérico' => ['12.abc.345/01de-35', '12ABC34501DE35'],
    'CPF'               => ['123.456.789-01', '12345678901'],
    'vazio'             => ['  ./- ', null],
    'null'              => [null, null],
]);

it('digits remove toda pontuação', function () {
    expect(BrazilianFormat::digits('(61) 99999-8888'))->toBe('61999998888')
        ->and(BrazilianFormat::digits(null))->toBe('');
});
