<?php

use App\Http\Controllers\PatientsController;

/**
 * A tabela de pacientes e o dropdown de busca exibem telefone/CPF formatados,
 * mas as colunas guardam só dígitos: um número copiado da tela e colado na
 * busca precisa casar pela versão só dígitos.
 */
function patientSearchDigits(string $term): ?string
{
    return (new ReflectionMethod(PatientsController::class, 'formattedNumberDigits'))->invoke(null, $term);
}

it('extrai os dígitos de número formatado colado na busca', function (string $term, string $digits) {
    expect(patientSearchDigits($term))->toBe($digits);
})->with([
    'celular'       => ['(61) 99999-8888', '61999998888'],
    'telefone fixo' => ['(61) 3333-4444', '6133334444'],
    'cpf'           => ['123.456.789-09', '12345678909'],
    'parcial'       => ['(61) 9999', '619999'],
]);

it('não gera busca por dígitos para nome, código ou termo já sem máscara', function (string $term) {
    expect(patientSearchDigits($term))->toBeNull();
})->with([
    'nome'            => ['Maria'],
    'nome com número' => ['João 1'],
    'só dígitos'      => ['61999998888'],
    'sem dígitos'     => ['(-)'],
]);
