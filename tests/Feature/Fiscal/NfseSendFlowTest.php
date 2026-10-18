<?php

use App\Enums\Fiscal\NfseStatus;
use App\Events\Billing\InvoicePaid;
use App\Models\Fiscal\NfseDocument;
use App\Services\Fiscal\NfseIssueService;
use App\Support\Fiscal\NfseSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/Support/NfseFixtures.php';

/**
 * Sem fake de fila: a fila é a síncrona dos testes, então o `SendNfseJob` despachado `afterCommit` pela emissão roda de
 * verdade. Prova a ligação emissão → fila → envio → Focus.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    nfseReady();
});

it('emitir enfileira o envio e o job leva a nota a processando_autorizacao', function () {
    Http::fake(['*/v2/nfse*' => Http::response(focusProcessing(), 201)]);

    $document = app(NfseIssueService::class)->issue(nfsePaidInvoice(), 'manual');

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(NfseStatus::Processing)
        ->and($fresh->response_payload['numero_rps'])->toBe('1')
        ->and($fresh->request_payload)->not->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "ref={$document->ref}"));
});

it('Focus recusando o conteúdo deixa a nota em erro_autorizacao com os motivos, sem derrubar quem emitiu', function () {
    Http::fake(['*' => Http::response(['codigo' => 'erro_validacao', 'mensagem' => 'Item da lista de serviços inválido'], 422)]);

    $document = app(NfseIssueService::class)->issue(nfsePaidInvoice(), 'manual');

    expect($document->fresh()->status)->toBe(NfseStatus::AuthorizationError)
        ->and(NfseDocument::query()->count())->toBe(1);
});

it('a emissão automática de ponta a ponta: InvoicePaid → nota → Focus', function () {
    (new NfseSettings())->save(['auto_issue' => true]);

    Http::fake(['*/v2/nfse*' => Http::response(focusProcessing(), 201)]);

    event(InvoicePaid::fromInvoice(nfsePaidInvoice()));

    expect(NfseDocument::query()->sole())
        ->status->toBe(NfseStatus::Processing)
        ->source->toBe('auto');
});
