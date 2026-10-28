<?php

use App\Http\Requests\{PatientRequest, QuickStorePatientRequest, ScheduleRequest, WaitingListRequest};
use Illuminate\Foundation\Http\FormRequest;

/**
 * Paciente, agenda, lista de espera e cadastro rápido recebem CPF/telefone
 * mascarados (v-mask 'cpf' / 'phone'); os FormRequests devem gravar só dígitos
 * — é o formato que o unique de people.national_registry, a busca por
 * telefone e o WhatsAppService::normalizePhone esperam.
 */
function prepareMaskedPayload(string $requestClass, array $payload): FormRequest
{
    /** @var FormRequest $request */
    $request = $requestClass::create('/', 'POST', $payload);

    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    return $request;
}

dataset('masked_phone_requests', [
    'paciente'        => PatientRequest::class,
    'agendamento'     => ScheduleRequest::class,
    'lista de espera' => WaitingListRequest::class,
]);

it('remove a máscara do CPF do paciente', function () {
    expect(prepareMaskedPayload(PatientRequest::class, ['national_registry' => '123.456.789-09'])->input('national_registry'))
        ->toBe('12345678909');
});

it('mantém CPF do paciente já sem máscara', function () {
    expect(prepareMaskedPayload(PatientRequest::class, ['national_registry' => '12345678909'])->input('national_registry'))
        ->toBe('12345678909');
});

it('mantém CPF do paciente null quando o campo vem limpo', function () {
    $request = prepareMaskedPayload(PatientRequest::class, ['national_registry' => null]);

    expect($request->has('national_registry'))->toBeTrue()
        ->and($request->input('national_registry'))->toBeNull();
});

it('converte CPF do paciente sem dígitos em null', function () {
    expect(prepareMaskedPayload(PatientRequest::class, ['national_registry' => '...-'])->input('national_registry'))
        ->toBeNull();
});

it('remove a máscara de telefone fixo e celular', function (string $requestClass) {
    $request = prepareMaskedPayload($requestClass, [
        'telephone' => '(61) 3333-4444',
        'cellphone' => '(61) 99999-8888',
    ]);

    expect($request->input('telephone'))->toBe('6133334444')
        ->and($request->input('cellphone'))->toBe('61999998888');
})->with('masked_phone_requests');

it('mantém telefone null quando o campo vem limpo', function (string $requestClass) {
    expect(prepareMaskedPayload($requestClass, ['telephone' => null])->input('telephone'))->toBeNull();
})->with('masked_phone_requests');

it('não inventa telefone quando o campo não vem no payload', function (string $requestClass) {
    $request = prepareMaskedPayload($requestClass, ['full_name' => 'Fulano']);

    expect($request->has('telephone'))->toBeFalse()
        ->and($request->has('cellphone'))->toBeFalse();
})->with('masked_phone_requests');

it('remove a máscara do celular no cadastro rápido de paciente', function () {
    expect(prepareMaskedPayload(QuickStorePatientRequest::class, ['cellphone' => '(61) 99999-8888'])->input('cellphone'))
        ->toBe('61999998888');
});
