<?php

use App\Domains\AI\Models\AiCreditPurchase;
use App\Enums\Fiscal\NfseStatus;
use App\Jobs\Fiscal\SendNfseJob;
use App\Models\{AuditLog, Entity};
use App\Models\Fiscal\NfseDocument;
use App\Support\Fiscal\NfseManagerPresenter;
use App\Support\PanelNavigation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Http, Queue, Storage};

require_once __DIR__ . '/Support/NfseFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();
    Queue::fake([SendNfseJob::class]);
    $this->saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
    nfseReady();
});

function nfseAuthDoc(array $overrides = []): NfseDocument
{
    $document = nfseDocument(nfsePaidInvoice(), array_replace(['status' => NfseStatus::Authorized->value, 'numero' => '77', 'request_payload' => ['prestador' => ['codigo_municipio' => '3550308']]], $overrides));
    $document->forceFill(['response_payload' => focusAuthorized() + ['ref' => $document->ref]])->save();

    return $document->fresh();
}

describe('emitir', function () {
    it('o financeiro emite a nota de uma fatura paga: 201 com a linha e o envio na fila', function () {
        $invoice = nfsePaidInvoice();

        nfseManager('financial')->postJson(route('manager.nfse.invoices.issue', $invoice))->assertCreated()
            ->assertJsonPath('document.status', 'pending_send')->assertJsonPath('document.invoice_id', $invoice->id)->assertJsonPath('document.source', 'manual');

        Queue::assertPushed(SendNfseJob::class, 1);
        expect(AuditLog::query()->where('event', 'manager.nfse.issue')->count())->toBe(1);
    });

    it('guarda barrada: 422 com o motivo traduzido e nenhuma nota', function () {
        $invoice = nfsePaidInvoice(null, ['amount' => 0]);

        nfseManager('admin')->postJson(route('manager.nfse.invoices.issue', $invoice))->assertStatus(422)
            ->assertJsonPath('reason', 'valor_zero')->assertJsonPath('message', __('fiscal.errors.valor_zero'));

        expect(NfseDocument::query()->count())->toBe(0);
    });

    it('fatura que já tem nota a caminho: 422 document_exists', function () {
        $invoice = nfsePaidInvoice();
        nfseDocument($invoice);

        nfseManager('financial')->postJson(route('manager.nfse.invoices.issue', $invoice))->assertStatus(422)->assertJsonPath('reason', 'document_exists');
    });

    it('depois de cancelada, a emissão manual é permitida', function () {
        $invoice = nfsePaidInvoice();
        nfseDocument($invoice, ['status' => NfseStatus::Cancelled->value]);

        nfseManager('admin')->postJson(route('manager.nfse.invoices.issue', $invoice))->assertCreated();
    });

    it('quem não é admin nem financeiro não emite', function () {
        nfseManager('support')->postJson(route('manager.nfse.invoices.issue', nfsePaidInvoice()))->assertForbidden();
    });
});

describe('cancelar', function () {
    it('só o admin cancela; a justificativa é validada; a Focus é chamada e a trilha guarda a justificativa', function () {
        $document = nfseAuthDoc();

        nfseManager('financial')->postJson(route('manager.nfse.documents.cancel', $document), ['justification' => 'Cancelamento a pedido do cliente'])->assertForbidden();
        nfseManager('admin')->postJson(route('manager.nfse.documents.cancel', $document), ['justification' => 'curta'])->assertStatus(422)->assertJsonValidationErrors(['justification']);

        Http::fake(['*' => fn (Request $r) => $r->method() === 'DELETE' ? Http::response(['status' => 'cancelado'], 200) : Http::response(array_replace(focusAuthorized(), ['status' => 'cancelado']), 200)]);

        nfseManager('admin')->postJson(route('manager.nfse.documents.cancel', $document), ['justification' => 'Cancelamento a pedido do cliente'])->assertOk()->assertJsonPath('document.status', 'cancelado');

        expect($document->fresh()->status)->toBe(NfseStatus::Cancelled)
            ->and(AuditLog::query()->where('event', 'manager.nfse.cancel')->first()->reason)->toBe('Cancelamento a pedido do cliente');
    });

    it('erro_cancelamento da Focus aparece na tela (422 com os erros) e a nota segue autorizada', function () {
        $document = nfseAuthDoc();

        Http::fake(['*' => Http::response(['status' => 'erro_cancelamento', 'erros' => [['codigo' => 'V999', 'mensagem' => 'fora do prazo', 'correcao' => null]]], 200)]);

        nfseManager('admin')->postJson(route('manager.nfse.documents.cancel', $document), ['justification' => 'Cancelamento a pedido do cliente'])->assertStatus(422)
            ->assertJsonPath('errors.0.mensagem', 'fora do prazo');

        expect($document->fresh()->status)->toBe(NfseStatus::Authorized);
    });

    it('nota não autorizada não se cancela (422) e município sem cancelamento orienta', function () {
        nfseManager('admin')->postJson(route('manager.nfse.documents.cancel', nfseAuthDoc(['status' => NfseStatus::Processing->value])), ['justification' => 'Cancelamento a pedido do cliente'])
            ->assertStatus(422)->assertJsonPath('reason', 'nota_nao_autorizada');

        nfseCacheMunicipality(['possui_cancelamento_nfse' => false]);

        nfseManager('admin')->postJson(route('manager.nfse.documents.cancel', nfseAuthDoc()), ['justification' => 'Cancelamento a pedido do cliente'])
            ->assertStatus(422)->assertJsonPath('reason', 'sem_cancelamento');
    });

    it('marcar como cancelada na prefeitura: só admin, motivo 20–255, sem chamar a Focus', function () {
        $document = nfseAuthDoc();

        nfseManager('financial')->postJson(route('manager.nfse.documents.mark-cancelled-externally', $document), ['reason' => 'Cancelada no portal da prefeitura'])->assertForbidden();
        nfseManager('admin')->postJson(route('manager.nfse.documents.mark-cancelled-externally', $document), ['reason' => 'curto'])->assertStatus(422);
        nfseManager('admin')->postJson(route('manager.nfse.documents.mark-cancelled-externally', $document), ['reason' => str_repeat('m', 19)])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        nfseManager('admin')->postJson(route('manager.nfse.documents.mark-cancelled-externally', $document), ['reason' => str_repeat('m', 256)])->assertStatus(422);

        Http::fake();

        nfseManager('admin')->postJson(route('manager.nfse.documents.mark-cancelled-externally', $document), ['reason' => 'Cancelada no portal da prefeitura'])->assertOk()
            ->assertJsonPath('document.cancelled_externally', true);

        Http::assertNothingSent();
    });
});

describe('reenviar e-mail, consultar e baixar XML', function () {
    it('reenvia a cópia para até 10 e-mails válidos, só de nota autorizada', function () {
        $document = nfseAuthDoc();
        Http::fake(['*/email' => Http::response(['emails' => ['a@x.test']], 200)]);

        nfseManager('financial')->postJson(route('manager.nfse.documents.resend-email', $document), ['emails' => ['a@x.test', 'b@x.test']])->assertOk();

        Http::assertSent(fn (Request $r) => $r->data() === ['emails' => ['a@x.test', 'b@x.test']] && str_ends_with($r->url(), "/v2/nfse/{$document->ref}/email"));

        nfseManager('financial')->postJson(route('manager.nfse.documents.resend-email', $document), ['emails' => array_map(fn ($i) => "u{$i}@x.test", range(1, 11))])->assertStatus(422);
        nfseManager('financial')->postJson(route('manager.nfse.documents.resend-email', $document), ['emails' => ['inválido']])->assertStatus(422);
        nfseManager('financial')->postJson(route('manager.nfse.documents.resend-email', nfseAuthDoc(['status' => NfseStatus::Processing->value])), ['emails' => ['a@x.test']])->assertStatus(422);
    });

    it('consultar agora aplica o que a Focus responde', function () {
        $document = nfseAuthDoc(['status' => NfseStatus::Processing->value]);

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200), '*' => Http::response(focusAuthorized() + ['ref' => $document->ref], 200)]);

        nfseManager('financial')->postJson(route('manager.nfse.documents.sync', $document))->assertOk()->assertJsonPath('document.status', 'autorizado');
    });

    it('baixa o XML guardado (busca na Focus se faltar) e 404 quando não existe', function () {
        $document = nfseAuthDoc();

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        $response = nfseManager('financial')->get(route('manager.nfse.documents.xml', $document))->assertOk();

        expect($response->streamedContent())->toBe(NFSE_XML)->and($response->headers->get('Content-Type'))->toContain('application/xml');

        nfseManager('financial')->get(route('manager.nfse.documents.cancel-xml', $document))->assertNotFound();
        nfseManager('support')->get(route('manager.nfse.documents.xml', $document))->assertForbidden();
    });
});

describe('contrato das linhas e dos drawers', function () {
    it('a linha traz só as ações que o status e o perfil permitem', function () {
        $authorized = nfseAuthDoc();
        $failed     = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::AuthorizationError->value, 'errors' => [['codigo' => 'E1', 'mensagem' => 'm']]]);

        $admin = nfseManager('admin')->get(route('manager.nfse.index'))->viewData('page')['props']['documents'];
        $rows  = collect($admin)->keyBy('id');

        expect(array_keys($rows[$authorized->id]['actions']))->toEqualCanonicalizing(['cancel', 'mark_cancelled_externally', 'resend_email', 'sync'])
            ->and(array_keys($rows[$failed->id]['actions']))->toBe(['issue'])
            ->and($rows[$failed->id]['errors'][0]['codigo'])->toBe('E1')
            ->and($rows[$authorized->id]['urls'])->toHaveKeys(['xml', 'cancel_xml', 'pdf', 'mirror'])
            ->and($rows[$authorized->id])->toHaveKeys(['id', 'ref', 'invoice_id', 'invoice_reference', 'entity', 'environment', 'standard', 'status', 'status_label', 'status_badge', 'numero', 'codigo_verificacao', 'amount', 'currency', 'issued_at', 'cancelled_at', 'cancelled_externally', 'cancel_required', 'cancel_required_reason', 'source', 'errors', 'urls', 'actions']);

        $financial = collect(nfseManager('financial')->get(route('manager.nfse.index'))->viewData('page')['props']['documents'])->keyBy('id');

        expect(array_keys($financial[$authorized->id]['actions']))->toEqualCanonicalizing(['resend_email', 'sync']);
    });

    it('o drawer da assinatura traz o bloco nfse por fatura, com o aviso de estorno parcial', function () {
        $document = nfseAuthDoc();
        $invoice  = $document->invoice;
        $invoice->update(['refunded_amount' => 50.00]);

        $rows = nfseManager('admin')->getJson(route('manager.subscriptions.invoices', $invoice->subscription_id))->assertOk()->json('data');

        expect($rows[0]['nfse']['id'])->toBe($document->id)
            ->and($rows[0]['nfse']['partial_refund_warning'])->toBeTrue()
            ->and($rows[0]['nfse']['actions'])->toHaveKey('cancel');

        // Sem estorno, ou estorno TOTAL (esse cancela a nota; não é aviso de parcial): sem aviso.
        foreach ([0, 199.90] as $refunded) {
            $invoice->update(['refunded_amount' => $refunded]);

            expect(nfseManager('admin')->getJson(route('manager.subscriptions.invoices', $invoice->subscription_id))->json('data.0.nfse.partial_refund_warning'))->toBeFalse();
        }
    });

    it('fatura sem nota: nfse nulo; o bloco prefere a nota que ocupa a fatura à última tentativa', function () {
        $invoice = nfsePaidInvoice();

        $none = nfseManager('admin')->getJson(route('manager.subscriptions.invoices', $invoice->subscription_id))->json('data.0.nfse');

        expect($none['status'])->toBeNull()
            ->and($none['status_label'])->toBe(__('fiscal.status.none'))
            ->and($none['actions'])->toBe(['issue' => route('manager.nfse.invoices.issue', $invoice)])
            ->and($none['last_attempt'])->toBeNull();

        $old = nfseDocument($invoice, ['status' => NfseStatus::AuthorizationError->value]);
        $new = nfseDocument($invoice, ['status' => NfseStatus::Processing->value]);

        expect(nfseManager('admin')->getJson(route('manager.subscriptions.invoices', $invoice->subscription_id))->json('data.0.nfse.id'))->toBe($new->id)->not->toBe($old->id);
    });

    it('fatura paga com só tentativa falha continua oferecendo "Emitir" e mostra a última tentativa', function () {
        $invoice = nfsePaidInvoice();
        $failed  = nfseDocument($invoice, ['status' => NfseStatus::AuthorizationError->value, 'errors' => [['codigo' => 'E1', 'mensagem' => 'motivo']]]);

        $block = nfseManager('admin')->getJson(route('manager.subscriptions.invoices', $invoice->subscription_id))->json('data.0.nfse');

        expect($block['status'])->toBeNull()
            ->and($block['actions'])->toHaveKey('issue')
            ->and($block['last_attempt']['id'])->toBe($failed->id)
            ->and($block['last_attempt']['errors'][0]['mensagem'])->toBe('motivo');
    });

    it('fatura não paga, estornada ou "pagamento não aplicado" não tem bloco (nulo)', function () {
        foreach ([['status' => 'pending'], ['status' => 'refunded', 'refunded_amount' => 199.90], ['refunded_amount' => 199.90], ['billing_reason' => 'unapplied_payment'], ['amount' => 0]] as $overrides) {
            $invoice = nfsePaidInvoice(null, $overrides);

            expect(nfseManager('admin')->getJson(route('manager.subscriptions.invoices', $invoice->subscription_id))->json('data.0.nfse'))->toBeNull();
        }
    });

    it('sem o Gate de emissão o bloco vem sem ação', function () {
        nfseManager('support');

        $blocks = app(NfseManagerPresenter::class)->forInvoices(collect([nfsePaidInvoice()]));

        expect(array_values($blocks)[0]['actions'])->toBe([])->and(array_values($blocks)[0]['status'])->toBeNull();
    });

    it('o detalhe do pacote de IA traz o bloco nfse da fatura dele e os textos t.nfse', function () {
        $clinic   = nfseClinic();
        $pack     = nfsePaidInvoice($clinic, ['billing_reason' => 'ai_credit_pack', 'subscription_id' => null, 'plan_id' => null, 'amount' => 49.90]);
        $purchase = AiCreditPurchase::factory()->create(['entity_id' => $clinic->id, 'invoice_id' => $pack->id]);
        $document = nfseDocument($pack, ['status' => NfseStatus::Authorized->value]);

        $json = nfseManager('financial')->getJson(route('manager.ai-credit-purchases.show', $purchase))->assertOk()->json();

        expect($json['purchase']['nfse']['id'])->toBe($document->id)
            ->and($json['purchase']['nfse']['status'])->toBe('autorizado')
            ->and($json['t']['nfse'])->toBeArray()->not->toBeEmpty();

        $other = AiCreditPurchase::factory()->create(['entity_id' => $clinic->id, 'invoice_id' => null]);

        // Pacote sem fatura: nada a emitir. Pacote com fatura paga e sem nota: "Emitir".
        expect(nfseManager('financial')->getJson(route('manager.ai-credit-purchases.show', $other))->json('purchase.nfse'))->toBeNull();

        $document->delete();

        expect(nfseManager('financial')->getJson(route('manager.ai-credit-purchases.show', $purchase))->json('purchase.nfse.actions.issue'))->toBe(route('manager.nfse.invoices.issue', $pack));
    });

    it('o item Nota fiscal está no menu do Manager', function () {
        $method = new ReflectionMethod(PanelNavigation::class, 'managerNav');
        $method->setAccessible(true);

        $nav = collect($method->invoke($method->isStatic() ? null : app(PanelNavigation::class)))->firstWhere('key', 'nfse');

        expect($nav)->not->toBeNull()->and($nav['route'])->toBe('manager.nfse.index')->and($nav['match'])->toBe(['manager.nfse.*']);
    });
});
