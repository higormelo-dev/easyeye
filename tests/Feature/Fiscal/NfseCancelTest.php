<?php

use App\Enums\Fiscal\NfseStatus;
use App\Events\Billing\InvoiceFullyReversed;
use App\Exceptions\Fiscal\{FocusNfeException, NfseIssueRejectedException as Rejected};
use App\Listeners\Fiscal\CancelNfseOnInvoiceFullyReversed;
use App\Models\{AuditLog, User};
use App\Models\Billing\BillingLog;
use App\Models\Fiscal\NfseDocument;
use App\Services\Fiscal\{NfseCancellationService, NfseSyncService};
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Http, Storage};
use Illuminate\Support\Facades\Lang;

require_once __DIR__ . '/Support/NfseFixtures.php';

const NFSE_JUSTIFICATION = 'Cancelamento a pedido do cliente (teste)';

/** Nota autorizada, como ficou depois da emissão (corpo enviado e resposta da Focus guardados). */
function nfseAuthorizedDoc(array $overrides = []): NfseDocument
{
    $document = nfseDocument(nfsePaidInvoice(), array_replace([
        'status'          => NfseStatus::Authorized->value,
        'numero'          => '1',
        'request_payload' => ['prestador' => ['cnpj' => NFSE_TEST_CNPJ, 'codigo_municipio' => '3550308']],
    ], $overrides));

    $document->forceFill(['response_payload' => focusAuthorized() + ['ref' => $document->ref]])->save();

    return $document->fresh();
}

/** DELETE → o que a Focus responde ao cancelamento; GET → a consulta que traz o XML do cancelamento. */
function nfseFakeCancel(array|Closure $delete = ['status' => 'cancelado'], int $deleteStatus = 200): void
{
    Http::fake([
        '*/arquivos/*' => Http::response(NFSE_XML, 200),
        '*'            => function (Request $request) use ($delete, $deleteStatus) {
            if ($request->method() === 'DELETE') {
                return Http::response($delete instanceof Closure ? $delete() : $delete, $deleteStatus);
            }

            return Http::response(array_replace(focusAuthorized(), [
                'status' => 'cancelado', 'caminho_xml_cancelamento' => '/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse-can.xml',
            ]), 200);
        },
    ]);
}

/** A justificativa do estorno/chargeback: a do arquivo de idioma (pt_BR) ou, sem ela, o texto padrão do serviço. */
function nfseReversalText(string $reason, string $reference): string
{
    $key = "fiscal.cancel.{$reason}_justification";

    return Lang::has($key, 'pt_BR') && mb_strlen(trans($key, ['referencia' => $reference], 'pt_BR')) >= 15
        ? trans($key, ['referencia' => $reference], 'pt_BR')
        : ($reason === 'chargeback' ? 'Chargeback da fatura ' : 'Estorno total da fatura ') . $reference;
}

function nfseDeletes(): int
{
    return Http::recorded(fn (Request $request) => $request->method() === 'DELETE')->count();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();
    nfseReady();
    $this->service = app(NfseCancellationService::class);
});

describe('cancelamento pelo Manager', function () {
    it('cancela na Focus (DELETE com a justificativa), grava o estado e traz o XML do cancelamento', function () {
        nfseFakeCancel();

        $document = nfseAuthorizedDoc();
        $result   = $this->service->cancel($document, '  ' . NFSE_JUSTIFICATION . '  ', User::factory()->create());

        expect($result)->toBe(['cancelled' => true, 'errors' => []]);

        $fresh = $document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Cancelled)
            ->and($fresh->justification)->toBe(NFSE_JUSTIFICATION)
            ->and($fresh->cancelled_at)->not->toBeNull()
            ->and($fresh->cancelled_externally)->toBeFalse()
            ->and($fresh->cancel_required)->toBeFalse()
            ->and($fresh->cancel_xml_path)->toEndWith('-cancel.xml');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === FOCUS_HOMOLOG . "/v2/nfse/{$document->ref}"
            && $request->data() === ['justificativa' => NFSE_JUSTIFICATION]);

        $audit = AuditLog::query()->where('auditable_type', NfseDocument::class)->where('auditable_id', $document->id)->where('event', 'updated')->get()
            ->first(fn ($row) => ($row->new_values['status'] ?? null) === 'cancelado');

        expect($audit)->not->toBeNull()
            ->and($audit->old_values['status'])->toBe('autorizado');
    });

    it('cancela no padrão nacional em /v2/nfsen', function () {
        nfseFakeCancel();

        $this->service->cancel(nfseAuthorizedDoc(['standard' => 'nacional']), NFSE_JUSTIFICATION);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/v2/nfsen/'));
    });

    it('erro_cancelamento volta para a tela com os erros e NÃO muda a nota', function () {
        nfseFakeCancel(['status' => 'erro_cancelamento', 'erros' => [['codigo' => 'V999', 'mensagem' => 'NFSe fora do prazo de cancelamento permitido', 'correcao' => null]]]);

        $document = nfseAuthorizedDoc();
        $result   = $this->service->cancel($document, NFSE_JUSTIFICATION);

        expect($result['cancelled'])->toBeFalse()
            ->and($result['errors'])->toBe([['codigo' => 'V999', 'mensagem' => 'NFSe fora do prazo de cancelamento permitido', 'correcao' => null]])
            ->and($document->fresh()->status)->toBe(NfseStatus::Authorized)
            ->and($document->fresh()->justification)->toBeNull();
    });

    it('justificativa fora de 15–255 é recusada antes de qualquer chamada', function (string $text) {
        Http::fake();

        $e = null;

        try {
            $this->service->cancel(nfseAuthorizedDoc(), $text);
        } catch (Rejected $e) {
        }

        expect($e?->reason)->toBe(Rejected::INVALID_JUSTIFICATION);
        Http::assertNothingSent();
    })->with(['curta' => 'muito curta', 'vazia' => '   ', 'longa' => str_repeat('x', 256)]);

    it('aceita justificativa de exatamente 15 e de 255 caracteres', function (int $length) {
        nfseFakeCancel();

        expect($this->service->cancel(nfseAuthorizedDoc(), str_repeat('j', $length))['cancelled'])->toBeTrue();
    })->with([15, 255]);

    it('só nota AUTORIZADA se cancela', function (NfseStatus $status) {
        Http::fake();

        $e = null;

        try {
            $this->service->cancel(nfseAuthorizedDoc(['status' => $status->value]), NFSE_JUSTIFICATION);
        } catch (Rejected $e) {
        }

        expect($e?->reason)->toBe(Rejected::NOT_AUTHORIZED);
        Http::assertNothingSent();
    })->with([NfseStatus::PendingSend, NfseStatus::SendFailed, NfseStatus::Processing, NfseStatus::AuthorizationError, NfseStatus::Cancelled]);

    it('400 nfe_nao_autorizada da Focus vira recusa traduzida, sem mudar a nota', function () {
        nfseFakeCancel(['codigo' => 'nfe_nao_autorizada', 'mensagem' => 'Nota fiscal não autorizada não pode ser cancelada'], 400);

        $document = nfseAuthorizedDoc();
        $e        = null;

        try {
            $this->service->cancel($document, NFSE_JUSTIFICATION);
        } catch (Rejected $e) {
        }

        expect($e?->reason)->toBe(Rejected::NOT_AUTHORIZED)->and($document->fresh()->status)->toBe(NfseStatus::Authorized);
    });

    it('município que não cancela por webservice: orientação (sem_cancelamento) e nenhuma chamada', function () {
        nfseCacheMunicipality(['possui_cancelamento_nfse' => false]);
        Http::fake();

        $e = null;

        try {
            $this->service->cancel(nfseAuthorizedDoc(), NFSE_JUSTIFICATION);
        } catch (Rejected $e) {
        }

        expect($e?->reason)->toBe(Rejected::NO_CANCELLATION);
        expect(nfseDeletes())->toBe(0);
    });

    it('falha de token ou de rede lança e a nota fica como estava', function (int $status) {
        Http::fake(['*' => Http::response('x', $status)]);

        $document = nfseAuthorizedDoc();

        expect(fn () => $this->service->cancel($document, NFSE_JUSTIFICATION))->toThrow(FocusNfeException::class)
            ->and($document->fresh()->status)->toBe(NfseStatus::Authorized);
    })->with([401, 502]);

    it('se a consulta pós-cancelamento falhar, a nota continua cancelada', function () {
        Http::fake(['*' => fn (Request $request) => $request->method() === 'DELETE' ? Http::response(['status' => 'cancelado'], 200) : Http::response('Bad Gateway', 502)]);

        $document = nfseAuthorizedDoc();

        expect($this->service->cancel($document, NFSE_JUSTIFICATION)['cancelled'])->toBeTrue()
            ->and($document->fresh()->status)->toBe(NfseStatus::Cancelled)
            ->and($document->fresh()->cancel_xml_path)->toBeNull();
    });
});

describe('cancelada na prefeitura (externamente)', function () {
    it('só registra: status, motivo, marca de externa e nenhuma chamada à Focus', function () {
        Http::fake();

        $document = nfseAuthorizedDoc(['cancel_required' => true, 'cancel_required_reason' => 'refund']);
        $fresh    = $this->service->markCancelledExternally($document, 'Cancelada no portal da prefeitura em 18/10', User::factory()->create());

        expect($fresh->status)->toBe(NfseStatus::Cancelled)
            ->and($fresh->cancelled_externally)->toBeTrue()
            ->and($fresh->justification)->toBe('Cancelada no portal da prefeitura em 18/10')
            ->and($fresh->cancel_required)->toBeFalse()
            ->and($fresh->cancel_required_reason)->toBeNull()
            ->and($fresh->cancelled_at)->not->toBeNull();

        Http::assertNothingSent();
    });

    it('exige motivo de 20–255 (a justificativa do cancelamento na Focus segue 15–255) e nota autorizada', function () {
        $authorized = nfseAuthorizedDoc();
        $processing = nfseAuthorizedDoc(['status' => NfseStatus::Processing->value]);

        expect(fn () => $this->service->markCancelledExternally($authorized, str_repeat('m', 19)))->toThrow(Rejected::class)
            ->and(fn () => $this->service->markCancelledExternally($authorized, str_repeat('m', 256)))->toThrow(Rejected::class)
            ->and(fn () => $this->service->markCancelledExternally($authorized, 'curto'))->toThrow(Rejected::class)
            ->and(fn () => $this->service->markCancelledExternally($processing, 'Motivo válido com mais de 15'))->toThrow(Rejected::class)
            ->and($authorized->fresh()->status)->toBe(NfseStatus::Authorized);

        // Limites exatos: 20 e 255 passam.
        foreach ([20, 255] as $length) {
            expect($this->service->markCancelledExternally(nfseAuthorizedDoc(), str_repeat('m', $length))->status)->toBe(NfseStatus::Cancelled);
        }
    });
});

describe('devolução total da fatura (estorno / chargeback)', function () {
    it('cancela a nota autorizada com a justificativa padrão do estorno', function () {
        nfseFakeCancel();

        $document = nfseAuthorizedDoc();

        $this->service->forReversal($document, 'refund');

        $reference = $document->invoice->reference;

        $expected = nfseReversalText('refund', $reference);

        expect($document->fresh()->status)->toBe(NfseStatus::Cancelled)
            ->and($document->fresh()->justification)->toBe($expected)
            ->and(mb_strlen($expected))->toBeGreaterThanOrEqual(15);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->data() === ['justificativa' => $expected]);
    });

    it('chargeback usa a justificativa de chargeback', function () {
        nfseFakeCancel();

        $document = nfseAuthorizedDoc();
        $this->service->forReversal($document, 'chargeback');

        expect($document->fresh()->justification)->toBe(nfseReversalText('chargeback', $document->invoice->reference));
    });

    it('município sem cancelamento por webservice: vira pendência, sem chamar a Focus', function () {
        nfseCacheMunicipality(['possui_cancelamento_nfse' => false]);
        Http::fake();

        $document = nfseAuthorizedDoc();
        $this->service->forReversal($document, 'refund');

        $fresh = $document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Authorized)
            ->and($fresh->cancel_required)->toBeTrue()
            ->and($fresh->cancel_required_reason)->toBe('refund');

        expect(BillingLog::query()->where('invoice_id', $document->invoice_id)->sole()->level)->toBe('warning');
        expect(nfseDeletes())->toBe(0);
    });

    it('erro_cancelamento ou nfe_nao_autorizada da Focus: pendência (a nota segue autorizada)', function (array $body, int $status) {
        nfseFakeCancel($body, $status);

        $document = nfseAuthorizedDoc();
        $this->service->forReversal($document, 'chargeback');

        expect($document->fresh())->status->toBe(NfseStatus::Authorized)->cancel_required->toBeTrue()->cancel_required_reason->toBe('chargeback');
    })->with([
        'erro_cancelamento'  => [['status' => 'erro_cancelamento', 'erros' => [['codigo' => 'V999', 'mensagem' => 'fora do prazo']]], 200],
        'nfe_nao_autorizada' => [['codigo' => 'nfe_nao_autorizada', 'mensagem' => 'não autorizada'], 400],
        'token recusado'     => [['codigo' => 'x'], 401],
    ]);

    it('Focus fora do ar relança, para a fila repetir — e NÃO marca pendência ainda', function () {
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $document = nfseAuthorizedDoc();

        expect(fn () => $this->service->forReversal($document, 'refund'))->toThrow(FocusNfeException::class)
            ->and($document->fresh()->cancel_required)->toBeFalse();
    });

    it('pendência não se repete no log nem na nota', function () {
        nfseCacheMunicipality(['possui_cancelamento_nfse' => false]);

        $document = nfseAuthorizedDoc();

        $this->service->forReversal($document, 'refund');
        $this->service->forReversal($document->fresh(), 'refund');

        expect(BillingLog::query()->where('invoice_id', $document->invoice_id)->count())->toBe(1);
    });

    it('nota ainda não enviada à Focus é anulada aqui, sem chamada', function (NfseStatus $status) {
        Http::fake();

        $document = nfseDocument(nfsePaidInvoice(), ['status' => $status->value]);
        $this->service->forReversal($document, 'refund');

        $fresh = $document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Cancelled)->and($fresh->cancelled_externally)->toBeFalse()->and($fresh->justification)->toBe(nfseReversalText('refund', $document->invoice->reference));
        Http::assertNothingSent();
    })->with([NfseStatus::PendingSend, NfseStatus::SendFailed]);

    it('pending_send/send_failed COM tentativa de envio pode estar na Focus: fica marcada, não é anulada', function (NfseStatus $status) {
        Http::fake();

        $document = nfseDocument(nfsePaidInvoice(), ['status' => $status->value, 'request_payload' => ['data_emissao' => '2026-10-18T10:00:00-03:00']]);
        $this->service->forReversal($document, 'refund');

        expect($document->fresh())->status->toBe($status)->cancel_required->toBeTrue();
        Http::assertNothingSent();
    })->with([NfseStatus::PendingSend, NfseStatus::SendFailed]);

    it('nota em voo (já houve envio) fica marcada e é cancelada assim que a Focus a autoriza', function () {
        $document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Processing->value, 'request_payload' => ['prestador' => ['codigo_municipio' => '3550308']]]);

        $this->service->forReversal($document, 'refund');

        expect($document->fresh())->status->toBe(NfseStatus::Processing)->cancel_required->toBeTrue();

        nfseFakeCancel();

        app(NfseSyncService::class)->apply($document->ref, $document->environment, focusAuthorized() + ['ref' => $document->ref], 'webhook');

        expect($document->fresh())->status->toBe(NfseStatus::Cancelled)->cancel_required->toBeFalse();
        expect(nfseDeletes())->toBe(1);
    });

    it('nota com erro ou já cancelada não é mexida', function (NfseStatus $status) {
        Http::fake();

        $document = nfseDocument(nfsePaidInvoice(), ['status' => $status->value]);
        $this->service->forReversal($document, 'refund');

        expect($document->fresh()->status)->toBe($status)->and($document->fresh()->cancel_required)->toBeFalse();
        Http::assertNothingSent();
    })->with([NfseStatus::AuthorizationError, NfseStatus::Cancelled]);
});

describe('listener do InvoiceFullyReversed (fila)', function () {
    it('fatura devolvida por inteiro cancela a nota dela e só a dela', function () {
        nfseFakeCancel();

        $document = nfseAuthorizedDoc();
        $other    = nfseAuthorizedDoc();

        event(new InvoiceFullyReversed($document->invoice_id, 'refund', 'corr-1'));

        expect($document->fresh()->status)->toBe(NfseStatus::Cancelled)
            ->and($other->fresh()->status)->toBe(NfseStatus::Authorized);
    });

    it('fatura sem nota é um no-op (nenhuma chamada)', function () {
        Http::fake();

        event(new InvoiceFullyReversed(nfsePaidInvoice()->id, 'refund'));

        Http::assertNothingSent();
    });

    it('a Focus fora do ar não vira exceção para o billing: a fila repete', function () {
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $document = nfseAuthorizedDoc();

        // Com a fila síncrona dos testes a falha sobe ao despachante; em produção é um job com 5 tentativas.
        expect(fn () => event(new InvoiceFullyReversed($document->invoice_id, 'refund')))->toThrow(FocusNfeException::class);
        expect(new CancelNfseOnInvoiceFullyReversed())->tries->toBe(5);
    });

    it('esgotadas as tentativas, failed() deixa a nota marcada para cancelar', function () {
        $document = nfseAuthorizedDoc();

        (new CancelNfseOnInvoiceFullyReversed())->failed(new InvoiceFullyReversed($document->invoice_id, 'chargeback'), new RuntimeException('x'));

        expect($document->fresh())->cancel_required->toBeTrue()->cancel_required_reason->toBe('chargeback');
    });

    it('roda na fila fiscal, depois do commit', function () {
        config(['fiscal.queue' => 'fiscal']);

        $listener = new CancelNfseOnInvoiceFullyReversed();

        expect($listener->viaQueue())->toBe('fiscal')->and($listener->afterCommit)->toBeTrue()->and($listener->backoff())->toBe([60, 300, 1800, 3600]);
    });
});
