<?php

use App\Http\Requests\DoctorRequest;
use App\Http\Requests\Manager\EntityRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * O front aplica máscara 00000-000 no CEP; os FormRequests devem gravar só dígitos
 * (mesmo formato do PatientRequest), senão médico/clínica ficam com CEP mascarado no banco.
 */
function prepareZipcode(string $requestClass, array $payload): FormRequest
{
    /** @var FormRequest $request */
    $request = $requestClass::create('/', 'POST', $payload);

    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    return $request;
}

dataset('zipcode_requests', [
    'doctor' => DoctorRequest::class,
    'entity' => EntityRequest::class,
]);

it('remove a máscara do CEP antes de validar', function (string $requestClass) {
    expect(prepareZipcode($requestClass, ['zipcode' => '01310-100'])->input('zipcode'))->toBe('01310100');
})->with('zipcode_requests');

it('mantém CEP já sem máscara', function (string $requestClass) {
    expect(prepareZipcode($requestClass, ['zipcode' => '01310100'])->input('zipcode'))->toBe('01310100');
})->with('zipcode_requests');

it('converte CEP sem dígitos em null', function (string $requestClass) {
    expect(prepareZipcode($requestClass, ['zipcode' => '-'])->input('zipcode'))->toBeNull();
})->with('zipcode_requests');

it('não inventa CEP quando o campo não vem no payload', function (string $requestClass) {
    expect(prepareZipcode($requestClass, ['city' => 'São Paulo'])->has('zipcode'))->toBeFalse();
})->with('zipcode_requests');
