<?php

use App\Http\Requests\DoctorRequest;
use App\Http\Requests\Manager\EntityRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * O front aplica máscara (v-mask) em CPF/CNPJ/telefone; os FormRequests de
 * médico e clínica devem gravar o valor canônico (mesmo formato do
 * PatientRequest/RegisterRequest), senão o CPF mascarado estoura o max:11 do
 * médico e o unique de people.national_registry compara formatado x dígitos.
 */
function prepareDocumentPhone(string $requestClass, array $payload): FormRequest
{
    /** @var FormRequest $request */
    $request = $requestClass::create('/', 'POST', $payload);

    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    return $request;
}

dataset('phone_requests', [
    'doctor' => DoctorRequest::class,
    'entity' => EntityRequest::class,
]);

it('remove a máscara de telefone fixo e celular', function (string $requestClass) {
    $request = prepareDocumentPhone($requestClass, [
        'telephone' => '(61) 3333-4444',
        'cellphone' => '(61) 99999-8888',
    ]);

    expect($request->input('telephone'))->toBe('6133334444')
        ->and($request->input('cellphone'))->toBe('61999998888');
})->with('phone_requests');

it('descarta o DDI 55 de telefone legado ou colado com código do país', function (string $requestClass, string $input, string $expected) {
    $request = prepareDocumentPhone($requestClass, ['telephone' => $input, 'cellphone' => $input]);

    expect($request->input('telephone'))->toBe($expected)
        ->and($request->input('cellphone'))->toBe($expected);
})->with('phone_requests')->with([
    'celular com +55 e máscara' => ['+55 (61) 99999-8888', '61999998888'],
    'celular legado 13 dígitos' => ['5561999998888', '61999998888'],
    'fixo legado 12 dígitos'    => ['556133334444', '6133334444'],
    'DDI + DDD 55 (RS)'         => ['5555999998888', '55999998888'],
]);

it('não confunde DDD 55 (RS) nem 0800 com DDI', function (string $requestClass, string $input) {
    expect(prepareDocumentPhone($requestClass, ['cellphone' => $input])->input('cellphone'))->toBe($input);
})->with('phone_requests')->with([
    'celular DDD 55' => ['55999998888'],
    'fixo DDD 55'    => ['5533334444'],
    '0800'           => ['08001234567'],
]);

it('converte telefone sem dígitos em null', function (string $requestClass) {
    expect(prepareDocumentPhone($requestClass, ['cellphone' => '('])->input('cellphone'))->toBeNull();
})->with('phone_requests');

it('não inventa telefone quando o campo não vem no payload', function (string $requestClass) {
    $request = prepareDocumentPhone($requestClass, ['name' => 'X']);

    expect($request->has('telephone'))->toBeFalse()
        ->and($request->has('cellphone'))->toBeFalse();
})->with('phone_requests');

it('remove a máscara do CPF do médico (cabe no max:11 e bate com o unique)', function () {
    expect(prepareDocumentPhone(DoctorRequest::class, ['national_registry' => '123.456.789-09'])->input('national_registry'))
        ->toBe('12345678909');
});

it('mantém CPF do médico já sem máscara', function () {
    expect(prepareDocumentPhone(DoctorRequest::class, ['national_registry' => '12345678909'])->input('national_registry'))
        ->toBe('12345678909');
});

it('remove a pontuação do CNPJ/CPF da clínica', function (string $input, string $expected) {
    expect(prepareDocumentPhone(EntityRequest::class, ['national_registration' => $input])->input('national_registration'))
        ->toBe($expected);
})->with([
    'cnpj numérico' => ['12.345.678/0001-90', '12345678000190'],
    'cpf'           => ['123.456.789-09', '12345678909'],
]);

it('preserva as letras do CNPJ alfanumérico da clínica (IN RFB 2.229/2024)', function () {
    expect(prepareDocumentPhone(EntityRequest::class, ['national_registration' => '12.abc.345/01de-35'])->input('national_registration'))
        ->toBe('12ABC34501DE35');
});

it('continua normalizando o CEP da clínica junto com os demais campos', function () {
    $request = prepareDocumentPhone(EntityRequest::class, [
        'zipcode'   => '01310-100',
        'telephone' => '(11) 3000-0000',
    ]);

    expect($request->input('zipcode'))->toBe('01310100')
        ->and($request->input('telephone'))->toBe('1130000000');
});
