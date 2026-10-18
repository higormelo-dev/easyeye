<?php

use App\Enums\Fiscal\{NfseEnvironment, NfseStandard};
use App\Exceptions\Fiscal\FocusNfeException;
use App\Services\Fiscal\NfseCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/Support/NfseFixtures.php';

beforeEach(fn () => Http::preventStrayRequests());

it('acha a credencial do ambiente pedido, sem misturar os dois', function () {
    $homolog = nfseCredential(NfseEnvironment::Homologation);
    $prod    = nfseCredential(NfseEnvironment::Production);

    $resolver = new NfseCredentialResolver();

    expect($resolver->for(NfseEnvironment::Homologation)->id)->toBe($homolog->id)
        ->and($resolver->for(NfseEnvironment::Production)->id)->toBe($prod->id)
        ->and($resolver->find(NfseEnvironment::Production)?->token)->toBe('tok-empresa-producao-SEGREDO');
});

it('lança credencial_ausente (traduzida) quando o ambiente não tem credencial', function () {
    try {
        (new NfseCredentialResolver())->for(NfseEnvironment::Production);
        $this->fail('esperava FocusNfeException');
    } catch (FocusNfeException $e) {
        expect($e->errorCode)->toBe(FocusNfeException::NOT_CONFIGURED)
            ->and($e->retryable())->toBeFalse()
            ->and($e->userMessage())->toBe(__('fiscal.errors.credencial_ausente'));
    }

    expect((new NfseCredentialResolver())->find(NfseEnvironment::Production))->toBeNull()
        ->and((new NfseCredentialResolver())->hasToken(NfseEnvironment::Production))->toBeFalse()
        ->and((new NfseCredentialResolver())->hasMasterToken(NfseEnvironment::Production))->toBeFalse();
});

it('entrega o client autenticado com o token da EMPRESA do ambiente', function () {
    nfseCredential(NfseEnvironment::Homologation, ['token' => 'tok-homolog-A']);
    nfseCredential(NfseEnvironment::Production, ['token' => 'tok-prod-B']);

    Http::fake(['*' => Http::response(focusProcessing(), 201)]);

    (new NfseCredentialResolver())->client(NfseEnvironment::Homologation)->issue(NfseStandard::Municipal, 'R1', []);
    (new NfseCredentialResolver())->client(NfseEnvironment::Production)->issue(NfseStandard::Municipal, 'R2', []);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), FOCUS_HOMOLOG) && $request->header('Authorization') === ['Basic ' . base64_encode('tok-homolog-A:')]);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), FOCUS_PROD) && $request->header('Authorization') === ['Basic ' . base64_encode('tok-prod-B:')]);
});

it('o client master usa o token master, não o da empresa', function () {
    nfseCredential(NfseEnvironment::Homologation, ['token' => 'tok-empresa', 'master_token' => 'tok-master']);

    Http::fake(['*' => Http::response(['codigo_municipio' => '3550308'], 200)]);

    (new NfseCredentialResolver())->masterClient(NfseEnvironment::Homologation)->municipality('3550308');

    Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Basic ' . base64_encode('tok-master:')]);
});

it('recusa o client quando falta justamente o token pedido, sem chamar a Focus', function () {
    nfseCredential(NfseEnvironment::Homologation, ['token' => null, 'master_token' => 'so-master']);
    nfseCredential(NfseEnvironment::Production, ['token' => 'so-empresa', 'master_token' => null]);

    $resolver = new NfseCredentialResolver();

    expect(fn () => $resolver->client(NfseEnvironment::Homologation))->toThrow(FocusNfeException::class)
        ->and(fn () => $resolver->masterClient(NfseEnvironment::Production))->toThrow(FocusNfeException::class)
        ->and($resolver->hasToken(NfseEnvironment::Production))->toBeTrue()
        ->and($resolver->hasMasterToken(NfseEnvironment::Production))->toBeFalse()
        ->and($resolver->hasMasterToken(NfseEnvironment::Homologation))->toBeTrue();

    Http::assertNothingSent();
});
