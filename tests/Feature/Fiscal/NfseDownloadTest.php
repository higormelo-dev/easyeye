<?php

use App\Enums\Fiscal\NfseStatus;
use App\Models\{Entity, User};
use App\Models\Fiscal\NfseDocument;
use App\Services\Fiscal\NfseDocumentStorage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Http, Storage};

require_once __DIR__ . '/Support/NfseFixtures.php';

/** Sessão da clínica (Minha assinatura) com o perfil dado. */
function nfseClinicUser(Entity $clinic, string $rule = 'admin', bool $owner = false): mixed
{
    $user = User::factory()->create();
    $link = createEntityUser($clinic, $user, $rule, isOwner: $owner);

    return test()->actingAs($user)->withSession(panelSession($link));
}

/** Nota autorizada da clínica, com o XML já no disco. */
function nfseClinicDoc(Entity $clinic, array $overrides = [], bool $withXml = true): NfseDocument
{
    $document = nfseDocument(nfsePaidInvoice($clinic), array_replace([
        'status' => NfseStatus::Authorized->value, 'numero' => '2026/42', 'issued_at' => now(), 'pdf_url' => 'https://focusnfe.s3.sa-east-1.amazonaws.com/n.pdf', 'url' => 'https://nfse.gov.br/n',
    ], $overrides));

    if ($withXml) {
        app(NfseDocumentStorage::class)->storeXml($document, NFSE_XML, NfseDocumentStorage::KIND_AUTHORIZED);
    }

    return $document->fresh();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();
    $this->clinic = nfseClinic();
    $this->other  = nfseClinic();
});

describe('download do XML pela clínica', function () {
    it('a clínica baixa o XML da própria nota (conteúdo do disco), sem cache público', function () {
        $document = nfseClinicDoc($this->clinic);

        $response = nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.xml', $document))->assertOk();

        expect($response->streamedContent())->toBe(NFSE_XML)
            ->and($response->headers->get('Content-Type'))->toContain('application/xml')
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
            ->and($response->headers->get('Content-Disposition'))->toContain('nfse-202642.xml');
    });

    it('nota de OUTRA clínica: 404 (nunca 403), nada vaza e nada é buscado', function () {
        $foreign = nfseClinicDoc($this->other);

        Http::fake();

        nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.xml', $foreign))->assertNotFound();
        nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.cancel-xml', $foreign))->assertNotFound();

        Http::assertNothingSent();
    });

    it('só admin, financeiro e dono (billing.contact); médico e secretária levam 403', function () {
        $document = nfseClinicDoc($this->clinic);

        nfseClinicUser($this->clinic, 'admin')->get(route('panel.my-subscription.nfse.xml', $document))->assertOk();
        nfseClinicUser($this->clinic, 'financial')->get(route('panel.my-subscription.nfse.xml', $document))->assertOk();
        nfseClinicUser($this->clinic, 'doctor', owner: true)->get(route('panel.my-subscription.nfse.xml', $document))->assertOk();
        nfseClinicUser($this->clinic, 'secretary')->get(route('panel.my-subscription.nfse.xml', $document))->assertForbidden();
        nfseClinicUser($this->clinic, 'doctor')->get(route('panel.my-subscription.nfse.xml', $document))->assertForbidden();
        $this->post(route('logout'));
        $this->get(route('panel.my-subscription.nfse.xml', $document))->assertRedirect();
    });

    it('XML ausente no disco: busca na Focus com o token do SERVIDOR, guarda e entrega', function () {
        nfseReady();
        $document = nfseClinicDoc($this->clinic, [], withXml: false);
        $document->forceFill(['response_payload' => focusAuthorized() + ['ref' => $document->ref]])->save();

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        $response = nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.xml', $document))->assertOk();

        expect($response->streamedContent())->toBe(NFSE_XML)->and($document->fresh()->xml_path)->not->toBeNull();

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Basic ' . base64_encode('tok-empresa-homologacao-SEGREDO:')]);
        expect($response->headers->get('Content-Disposition'))->not->toContain('SEGREDO');
    });

    it('sem arquivo e sem como buscar: 404', function () {
        $document = nfseClinicDoc($this->clinic, [], withXml: false);

        Http::fake();

        nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.xml', $document))->assertNotFound();
    });

    it('nota que a clínica não vê (pendente, processando, com erro) é 404', function (NfseStatus $status) {
        $document = nfseClinicDoc($this->clinic, ['status' => $status->value]);

        nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.xml', $document))->assertNotFound();
    })->with([NfseStatus::PendingSend, NfseStatus::SendFailed, NfseStatus::Processing, NfseStatus::AuthorizationError]);

    it('XML do cancelamento: só nota cancelada pelo sistema; a cancelada na prefeitura não tem', function () {
        $cancelled = nfseClinicDoc($this->clinic, ['status' => NfseStatus::Cancelled->value]);
        app(NfseDocumentStorage::class)->storeXml($cancelled, NFSE_XML, NfseDocumentStorage::KIND_CANCEL);

        nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.cancel-xml', $cancelled))->assertOk();

        $external   = nfseClinicDoc($this->clinic, ['status' => NfseStatus::Cancelled->value, 'cancelled_externally' => true]);
        $authorized = nfseClinicDoc($this->clinic);

        nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.cancel-xml', $external))->assertNotFound();
        nfseClinicUser($this->clinic)->get(route('panel.my-subscription.nfse.cancel-xml', $authorized))->assertNotFound();
    });

    it('limita as chamadas (billing-checkout-read) e exige uuid na rota', function () {
        nfseClinicUser($this->clinic)->get('/panel/my-subscription/nfse/nao-e-uuid/xml')->assertNotFound();

        $middleware = collect(app('router')->getRoutes()->getByName('panel.my-subscription.nfse.xml')->gatherMiddleware());

        expect($middleware)->toContain('throttle:billing-checkout-read')->toContain('billing.contact');
    });
});

describe('bloco nfse do resumo (Minha assinatura)', function () {
    it('fatura com nota autorizada traz número, PDF, XML e links; sem nota, nulo', function () {
        $document = nfseClinicDoc($this->clinic);
        $invoice  = nfsePaidInvoice($this->clinic);

        $rows = collect(nfseClinicUser($this->clinic)->getJson(route('panel.my-subscription.summary'))->assertOk()->json('data.invoices') ?? [])->keyBy('id');

        expect($rows[$document->invoice_id]['nfse'])->toBe([
            'status'         => 'autorizado',
            'number'         => '2026/42',
            'issued_at'      => $document->issued_at->toIso8601String(),
            'pdf_url'        => 'https://focusnfe.s3.sa-east-1.amazonaws.com/n.pdf',
            'xml_url'        => route('panel.my-subscription.nfse.xml', $document),
            'cancel_xml_url' => null,
            'mirror_url'     => 'https://nfse.gov.br/n',
            'cancelled'      => false,
        ])->and($rows[$invoice->id]['nfse'])->toBeNull();
    });

    it('nota cancelada aparece como cancelada, com o XML do cancelamento quando há', function () {
        $document = nfseClinicDoc($this->clinic, ['status' => NfseStatus::Cancelled->value]);
        app(NfseDocumentStorage::class)->storeXml($document, NFSE_XML, NfseDocumentStorage::KIND_CANCEL);

        $row = collect(nfseClinicUser($this->clinic)->getJson(route('panel.my-subscription.summary'))->json('data.invoices'))->firstWhere('id', $document->invoice_id);

        expect($row['nfse']['cancelled'])->toBeTrue()->and($row['nfse']['cancel_xml_url'])->toBe(route('panel.my-subscription.nfse.cancel-xml', $document));
    });

    it('a clínica nunca vê nota pendente, em erro nem a de outra clínica; nem ref, erros ou ações', function () {
        $mine    = nfseDocument(nfsePaidInvoice($this->clinic), ['status' => NfseStatus::Processing->value]);
        $failed  = nfseDocument(nfsePaidInvoice($this->clinic), ['status' => NfseStatus::AuthorizationError->value, 'errors' => [['codigo' => 'E1', 'mensagem' => 'segredo-do-erro']]]);
        $foreign = nfseClinicDoc($this->other);

        $response = nfseClinicUser($this->clinic)->getJson(route('panel.my-subscription.summary'))->assertOk();
        $rows     = collect($response->json('data.invoices'))->keyBy('id');

        expect($rows[$mine->invoice_id]['nfse'])->toBeNull()
            ->and($rows[$failed->invoice_id]['nfse'])->toBeNull()
            ->and($rows)->not->toHaveKey($foreign->invoice_id);

        $dump = $response->getContent();

        expect($dump)->not->toContain('segredo-do-erro')->not->toContain($foreign->ref)->not->toContain($mine->ref)->not->toContain('"actions"');
    });

    it('no máximo uma nota por fatura: a autorizada vence a cancelada anterior', function () {
        $invoice = nfsePaidInvoice($this->clinic);
        nfseDocument($invoice, ['status' => NfseStatus::Cancelled->value, 'numero' => '10']);
        nfseDocument($invoice, ['status' => NfseStatus::Authorized->value, 'numero' => '11']);

        $row = collect(nfseClinicUser($this->clinic)->getJson(route('panel.my-subscription.summary'))->json('data.invoices'))->firstWhere('id', $invoice->id);

        expect($row['nfse']['number'])->toBe('11')->and($row['nfse']['cancelled'])->toBeFalse();
    });
});
