<?php

use App\Http\Controllers\Partner\LeadsController;
use App\Http\Controllers\Stock\SuppliersController;
use App\Http\Requests\Manager\PartnerRequest;
use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\PartnerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * A UI aplica máscara (v-mask) em CNPJ/CPF e telefone de parceiros, fornecedores
 * e leads do portal; o backend deve gravar só dígitos (formato canônico).
 */

// ── Parceiro (Manager) — document CNPJ/CPF ─────────────────────────────────────

it('parceiro: remove a máscara de CNPJ e de CPF do document', function (string $masked, string $digits) {
    $request = PartnerRequest::create('/', 'POST', ['document' => $masked]);
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('document'))->toBe($digits);
})->with([
    'cnpj' => ['12.345.678/0001-90', '12345678000190'],
    'cpf'  => ['123.456.789-01', '12345678901'],
    'cru'  => ['12345678901', '12345678901'],
]);

it('parceiro: document sem dígitos vira null e ausente não é inventado', function () {
    $semDigitos = PartnerRequest::create('/', 'POST', ['document' => './-']);
    (new ReflectionMethod($semDigitos, 'prepareForValidation'))->invoke($semDigitos);

    $ausente = PartnerRequest::create('/', 'POST', ['name' => 'Parceiro']);
    (new ReflectionMethod($ausente, 'prepareForValidation'))->invoke($ausente);

    expect($semDigitos->input('document'))->toBeNull()
        ->and($ausente->has('document'))->toBeFalse();
});

// ── Fornecedor (Estoque) — phone (document já era normalizado) ─────────────────

it('fornecedor: remove a máscara do telefone fixo e do celular', function (string $masked, string $digits) {
    $request = SupplierRequest::create('/', 'POST', ['phone' => $masked]);
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('phone'))->toBe($digits);
})->with([
    'fixo'    => ['(61) 3333-4444', '6133334444'],
    'celular' => ['(61) 99999-8888', '61999998888'],
]);

it('fornecedor: telefone sem dígitos vira null, ausente não é inventado e document segue normalizado', function () {
    $request = SupplierRequest::create('/', 'POST', ['phone' => '-', 'document' => '12.345.678/0001-99']);
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    $ausente = SupplierRequest::create('/', 'POST', ['name' => 'Fornecedor']);
    (new ReflectionMethod($ausente, 'prepareForValidation'))->invoke($ausente);

    expect($request->input('phone'))->toBeNull()
        ->and($request->input('document'))->toBe('12345678000199')
        ->and($ausente->has('phone'))->toBeFalse();
});

/** Request de edição (PUT) com o fornecedor gravado resolvido pela rota, como no route model binding. */
function supplierUpdateRequestWithStoredPhone(array $input, ?string $storedPhone): SupplierRequest
{
    $request = SupplierRequest::create('/panel/stock/suppliers/1', 'PUT', $input);
    $route   = (new Route('PUT', 'panel/stock/suppliers/{supplier}', []))->bind($request);
    $route->setParameter('supplier', (new Supplier())->forceFill(['phone' => $storedPhone]));
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('fornecedor: na edição, telefone legado reenviado sem alteração fica intacto', function (string $legacy) {
    $request = supplierUpdateRequestWithStoredPhone(['phone' => $legacy, 'name' => 'Fornecedor'], $legacy);
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('phone'))->toBe($legacy);
})->with([
    'com ramal'      => ['(61) 3333-4444 r.21'],
    'dois números'   => ['61 9999-8888 / 61 3333-4444'],
    'não geográfico' => ['4004-0001'],
]);

it('fornecedor: na edição, telefone alterado pelo usuário é normalizado', function () {
    $request = supplierUpdateRequestWithStoredPhone(['phone' => '(61) 99999-8888'], '(61) 3333-4444 r.21');
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('phone'))->toBe('61999998888');
});

it('fornecedor: listagem formata documento/telefone e devolve legado intacto', function (?string $document, ?string $phone, ?string $documentDisplay, ?string $phoneDisplay) {
    $record = (new Supplier())->forceFill(['document' => $document, 'phone' => $phone]);

    $fields = (new ReflectionMethod(SuppliersController::class, 'displayFields'))->invoke(null, $record);

    expect($fields)->toBe(['document_display' => $documentDisplay, 'phone_display' => $phoneDisplay]);
})->with([
    'cnpj + fixo'           => ['12345678000199', '6133334444', '12.345.678/0001-99', '(61) 3333-4444'],
    'cpf + celular'         => ['12345678901', '61999998888', '123.456.789-01', '(61) 99999-8888'],
    'telefone com ramal'    => [null, '(61) 3333-4444 r.21', null, '(61) 3333-4444 r.21'],
    'não geográfico 8 díg.' => [null, '40040001', null, '4004-0001'],
    'dígitos concatenados'  => [null, '613333444421', null, '613333444421'],
    'documento texto livre' => ['ISENTO', null, 'ISENTO', null],
    'vazios'                => [null, null, null, null],
]);

it('fornecedor: busca por documento pontuado também procura pelos dígitos', function (string $search, string $expected) {
    $method = new ReflectionMethod(SuppliersController::class, 'documentSearchDigits');

    expect($method->invoke(null, $search))->toBe($expected);
})->with([
    'cnpj formatado'       => ['12.345.678/0001-99', '12345678000199'],
    'cpf formatado'        => ['123.456.789-01', '12345678901'],
    'só dígitos'           => ['12345678000199', ''],
    'nome com número'      => ['Alfa 3', ''],
    'nome'                 => ['Óptica Central', ''],
    'pontuação sem dígito' => ['./-', ''],
]);

// ── Lead do portal do parceiro — phone (validate inline no controller) ─────────

it('lead do portal: remove a máscara do telefone antes de validar', function () {
    $request = Request::create('/', 'POST', ['phone' => '(61) 99999-8888']);

    (new ReflectionMethod(LeadsController::class, 'normalizePhone'))
        ->invoke(new LeadsController(new PartnerService()), $request);

    expect($request->input('phone'))->toBe('61999998888');
});

it('lead do portal: telefone sem dígitos vira null e ausente não é inventado', function () {
    $controller = new LeadsController(new PartnerService());
    $method     = new ReflectionMethod(LeadsController::class, 'normalizePhone');

    $semDigitos = Request::create('/', 'POST', ['phone' => '()-']);
    $method->invoke($controller, $semDigitos);

    $ausente = Request::create('/', 'POST', ['name' => 'Lead']);
    $method->invoke($controller, $ausente);

    expect($semDigitos->input('phone'))->toBeNull()
        ->and($ausente->has('phone'))->toBeFalse();
});
