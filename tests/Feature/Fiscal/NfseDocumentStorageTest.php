<?php

use App\Enums\Fiscal\{NfseEnvironment, NfseStatus};
use App\Services\Fiscal\NfseDocumentStorage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Http, Log, Storage};

require_once __DIR__ . '/Support/NfseFixtures.php';

beforeEach(function () {
    Storage::fake();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-18 12:00:00');
    $this->storage  = app(NfseDocumentStorage::class);
    $this->document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Authorized->value]);
});

afterEach(fn () => Carbon::setTestNow());

it('grava o XML de autorização em nfse/{ambiente}/{Y}/{m}/{ref}.xml e aponta a nota para ele', function () {
    $path = $this->storage->storeXml($this->document, NFSE_XML, NfseDocumentStorage::KIND_AUTHORIZED);

    expect($path)->toBe("nfse/homologacao/2026/10/{$this->document->ref}.xml")
        ->and($this->document->fresh()->xml_path)->toBe($path)
        ->and($this->document->fresh()->cancel_xml_path)->toBeNull();

    Storage::disk()->assertExists($path);

    expect(Storage::disk()->get($path))->toBe(NFSE_XML);
});

it('grava o XML de cancelamento ao lado, com sufixo -cancel', function () {
    $path = $this->storage->storeXml($this->document, NFSE_XML, NfseDocumentStorage::KIND_CANCEL);

    expect($path)->toBe("nfse/homologacao/2026/10/{$this->document->ref}-cancel.xml")
        ->and($this->document->fresh()->cancel_xml_path)->toBe($path)
        ->and($this->document->fresh()->xml_path)->toBeNull();
});

it('o caminho depende do ambiente e do mês de criação da nota, não de quando se grava', function () {
    $prod = nfseDocument(nfsePaidInvoice(), ['environment' => NfseEnvironment::Production->value]);

    Carbon::setTestNow('2027-01-05 08:00:00');

    expect($this->storage->path($prod, NfseDocumentStorage::KIND_AUTHORIZED))->toBe("nfse/producao/2026/10/{$prod->ref}.xml")
        ->and($this->storage->path($this->document, NfseDocumentStorage::KIND_AUTHORIZED))->toBe("nfse/homologacao/2026/10/{$this->document->ref}.xml");
});

it('regravar o mesmo XML é idempotente', function () {
    $first  = $this->storage->storeXml($this->document, NFSE_XML, NfseDocumentStorage::KIND_AUTHORIZED);
    $second = $this->storage->storeXml($this->document->fresh(), NFSE_XML, NfseDocumentStorage::KIND_AUTHORIZED);

    expect($second)->toBe($first)
        ->and(Storage::disk()->allFiles('nfse'))->toHaveCount(1);
});

it('recusa o que não é XML (página de erro, vazio, texto) e não grava nada', function (string $contents) {
    expect(fn () => $this->storage->storeXml($this->document, $contents, NfseDocumentStorage::KIND_AUTHORIZED))->toThrow(InvalidArgumentException::class);

    expect(Storage::disk()->allFiles())->toBe([])
        ->and($this->document->fresh()->xml_path)->toBeNull();
})->with([
    'vazio'        => '',
    'texto'        => 'HTTP Basic: Access denied',
    'json'         => '{"codigo":"nao_encontrado"}',
    'xml quebrado' => '<nfse><aberto>',
    'enorme'       => '<a>' . str_repeat('x', 5_100_000) . '</a>',
]);

it('recusa tipo de XML desconhecido', function () {
    expect(fn () => $this->storage->storeXml($this->document, NFSE_XML, 'outro'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->storage->path($this->document, 'outro'))->toThrow(InvalidArgumentException::class);
});

it('não resolve entidade externa do XML (XXE)', function () {
    $evil = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>';

    // Bem formado, então guarda — mas guardar não lê o arquivo do disco para dentro de nada.
    $path = $this->storage->storeXml($this->document, $evil, NfseDocumentStorage::KIND_AUTHORIZED);

    expect(Storage::disk()->get($path))->toBe($evil)->and(Storage::disk()->get($path))->not->toContain('root:');
});

it('lê o conteúdo guardado e devolve null sem arquivo', function () {
    expect($this->storage->contents($this->document, NfseDocumentStorage::KIND_AUTHORIZED))->toBeNull();

    $this->storage->storeXml($this->document, NFSE_XML, NfseDocumentStorage::KIND_AUTHORIZED);

    expect($this->storage->contents($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBe(NFSE_XML)
        ->and($this->storage->contents($this->document->fresh(), NfseDocumentStorage::KIND_CANCEL))->toBeNull();

    Storage::disk()->delete($this->document->fresh()->xml_path);

    expect($this->storage->contents($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBeNull();
});

it('ensure() entrega do disco sem chamar a Focus', function () {
    $this->storage->storeXml($this->document, NFSE_XML, NfseDocumentStorage::KIND_AUTHORIZED);

    Http::fake();

    expect($this->storage->ensure($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBe(NFSE_XML);

    Http::assertNothingSent();
});

it('ensure() busca na Focus pelo caminho relativo, guarda e entrega quando o arquivo não está no disco', function () {
    nfseCredential(NfseEnvironment::Homologation, ['token' => 'tok-doc']);

    $this->document->update(['response_payload' => focusAuthorized()]);

    Http::fake(['*' => Http::response(NFSE_XML, 200, ['Content-Type' => 'application/xml'])]);

    $xml = $this->storage->ensure($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED);

    expect($xml)->toBe(NFSE_XML)
        ->and($this->document->fresh()->xml_path)->toBe("nfse/homologacao/2026/10/{$this->document->ref}.xml");

    Storage::disk()->assertExists($this->document->fresh()->xml_path);

    Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . '/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse.xml'
        && $request->header('Authorization') === ['Basic ' . base64_encode('tok-doc:')]);
});

it('ensure() busca o XML de cancelamento pelo caminho de cancelamento', function () {
    nfseCredential(NfseEnvironment::Homologation);

    $this->document->update(['response_payload' => ['caminho_xml_nota_fiscal' => '/arquivos/a/n.xml', 'caminho_xml_cancelamento' => '/arquivos/a/n-can.xml']]);

    Http::fake(['*' => Http::response(NFSE_XML, 200)]);

    expect($this->storage->ensure($this->document->fresh(), NfseDocumentStorage::KIND_CANCEL))->toBe(NFSE_XML);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/arquivos/a/n-can.xml'));

    expect($this->document->fresh()->cancel_xml_path)->toEndWith('-cancel.xml')
        ->and($this->document->fresh()->xml_path)->toBeNull();
});

it('ensure() devolve null, sem chamar a Focus, quando a nota não tem caminho', function () {
    Http::fake();

    expect($this->storage->ensure($this->document, NfseDocumentStorage::KIND_AUTHORIZED))->toBeNull();

    Http::assertNothingSent();
});

it('ensure() não derruba quem chamou quando a Focus falha: devolve null e registra', function () {
    nfseCredential(NfseEnvironment::Homologation);

    $this->document->update(['response_payload' => focusAuthorized()]);

    Log::spy();
    Http::fake(['*' => Http::response('Bad Gateway', 502)]);

    expect($this->storage->ensure($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBeNull()
        ->and($this->document->fresh()->xml_path)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'nfse.xml.unavailable' && ($context['codigo'] ?? null) === 'http')->once();
});

it('ensure() não guarda resposta que não é XML', function () {
    nfseCredential(NfseEnvironment::Homologation);

    $this->document->update(['response_payload' => focusAuthorized()]);

    Http::fake(['*' => Http::response('<html><body>login</body></html', 200)]);

    expect($this->storage->ensure($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBeNull()
        ->and(Storage::disk()->allFiles())->toBe([]);
});

it('ensure() sem credencial do ambiente devolve null (não explode a rota de download)', function () {
    $this->document->update(['response_payload' => focusAuthorized()]);

    Http::fake();

    expect($this->storage->ensure($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBeNull();

    Http::assertNothingSent();
});

it('monta o nome do arquivo de download pelo número da nota, sem caracteres perigosos', function () {
    $this->document->update(['numero' => '2026/0042']);

    expect($this->storage->downloadName($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBe('nfse-20260042.xml')
        ->and($this->storage->downloadName($this->document->fresh(), NfseDocumentStorage::KIND_CANCEL))->toBe('nfse-20260042-cancelamento.xml');

    $this->document->update(['numero' => null]);

    expect($this->storage->downloadName($this->document->fresh(), NfseDocumentStorage::KIND_AUTHORIZED))->toBe("nfse-{$this->document->ref}.xml");
});
