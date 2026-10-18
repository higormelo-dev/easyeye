<?php

use App\Domains\AI\Models\AiCreditPurchase;
use App\Enums\Fiscal\NfseStatus;
use App\Exceptions\Fiscal\FocusNfeException;
use App\Jobs\Fiscal\SendNfseJob;
use App\Models\AuditLog;
use App\Models\Billing\BillingLog;
use App\Models\Fiscal\{NfseCredential, NfseDocument};
use App\Services\Fiscal\{NfseIssueService, NfseSendService};
use App\Support\Fiscal\NfseSettings;
use Illuminate\Http\Client\{ConnectionException, Request};
use Illuminate\Support\Facades\{Http, Log, Queue};

require_once __DIR__ . '/Support/NfseFixtures.php';

function nfseRunSend(NfseDocument $document): void
{
    (new SendNfseJob($document->id))->handle(app(NfseSendService::class));
}

/** O corpo (JSON) que cada POST de emissão levou, na ordem (as conferências GET não entram). */
function nfseSentBodies(): array
{
    return Http::recorded()->filter(fn (array $pair) => $pair[0]->method() === 'POST')->map(fn (array $pair) => $pair[0]->data())->values()->all();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([SendNfseJob::class]);

    nfseReady();

    $this->invoice  = nfsePaidInvoice();
    $this->document = app(NfseIssueService::class)->issue($this->invoice, 'manual');
});

describe('envio municipal', function () {
    it('POST /v2/nfse?ref= com o token da empresa e passa a processando_autorizacao', function () {
        Http::fake(['*/v2/nfse*' => Http::response(focusProcessing(), 201)]);

        nfseRunSend($this->document);

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::Processing)
            ->and($document->response_payload)->toEqual(focusProcessing())  // jsonb não guarda a ordem das chaves
            ->and($document->response_payload['numero_rps'])->toBe('1')
            ->and($document->errors)->toBeNull();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === FOCUS_HOMOLOG . "/v2/nfse?ref={$this->document->ref}"
            && $request->header('Authorization') === ['Basic ' . base64_encode('tok-empresa-homologacao-SEGREDO:')]);
    });

    it('monta o corpo com o prestador, o tomador congelado e o texto da fatura', function () {
        Http::fake(['*' => Http::response(focusProcessing(), 201)]);

        nfseRunSend($this->document);

        $body = nfseSentBodies()[0];

        expect($body['data_emissao'])->toBe($this->document->created_at->toIso8601String())
            ->and($body['natureza_operacao'])->toBe('1')
            ->and($body['optante_simples_nacional'])->toBeTrue()
            ->and($body['prestador'])->toBe(['cnpj' => NFSE_TEST_CNPJ, 'inscricao_municipal' => '12345678', 'codigo_municipio' => '3550308'])
            ->and($body['tomador']['cnpj'])->toBe($this->invoice->entity->national_registration)
            ->and($body['tomador']['razao_social'])->toBe('CLINICA OFTALMO TESTE')
            ->and($body['tomador']['endereco']['codigo_municipio'])->toBe('3550308')
            ->and($body['servico'])->toMatchArray([
                'valor_servicos'     => 199.9,
                'iss_retido'         => false,
                'item_lista_servico' => '01.07',
                'codigo_municipio'   => '3550308',
                'aliquota'           => 0.05,
            ])
            ->and($body['servico']['discriminacao'])->toContain('Plano Pro')->toContain('01/10/2026 a 31/10/2026')->toContain($this->invoice->reference);
    });

    it('o teto da discriminação do municipal vem da configuração', function () {
        config(['fiscal.limits.discriminacao' => 40]);
        (new NfseSettings())->save(['discriminacao' => ['plano' => str_repeat('Texto longo ', 20)]]);

        $document = app(NfseIssueService::class)->issue(nfsePaidInvoice(), 'auto');

        Http::fake(['*' => Http::response(focusProcessing(), 201)]);

        nfseRunSend($document);

        expect(mb_strlen(nfseSentBodies()[0]['servico']['discriminacao']))->toBe(40);
    });

    it('guarda o corpo enviado (saneado) na nota', function () {
        Http::fake(['*' => Http::response(focusProcessing(), 201)]);

        nfseRunSend($this->document);

        expect($this->document->fresh()->request_payload)->toEqual(nfseSentBodies()[0]);
    });

    it('o texto da nota usa o modelo do pacote de IA, com os créditos comprados', function () {
        $clinic = nfseClinic();
        $pack   = nfsePaidInvoice($clinic, ['billing_reason' => 'ai_credit_pack', 'subscription_id' => null, 'plan_id' => null, 'amount' => 49.90]);

        AiCreditPurchase::factory()->create(['entity_id' => $clinic->id, 'invoice_id' => $pack->id, 'credits' => 1500]);

        $document = app(NfseIssueService::class)->issue($pack, 'auto');

        Http::fake(['*' => Http::response(focusProcessing(), 201)]);

        nfseRunSend($document);

        expect(nfseSentBodies()[0]['servico']['discriminacao'])->toBe("Pacote de créditos de IA (1.500 créditos) — fatura {$pack->reference}")
            ->and(nfseSentBodies()[0]['servico']['valor_servicos'])->toBe(49.9);
    });
});

describe('envio nacional', function () {
    it('POST /v2/nfsen com série e número da DPS reservados e data em UTC', function () {
        (new NfseSettings())->save(['standard' => 'nacional', 'prestador' => ['serie_dps' => 2, 'proximo_numero_dps' => 77]]);

        $document = app(NfseIssueService::class)->issue(nfsePaidInvoice(), 'auto');

        Http::fake(['*/v2/nfsen*' => Http::response(['cnpj_prestador' => '12345678000123', 'ref' => $document->ref, 'status' => 'processando_autorizacao'], 202)]);

        nfseRunSend($document);

        expect($document->fresh()->status)->toBe(NfseStatus::Processing);

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . "/v2/nfsen?ref={$document->ref}");

        $body = nfseSentBodies()[0];

        expect($body)->toMatchArray(['serie_dps' => 2, 'numero_dps' => 77, 'emitente_dps' => 1, 'valor_servico' => 199.9, 'tributacao_iss' => 1, 'codigo_municipio_prestacao' => 3550308])
            ->and($body['data_emissao'])->toEndWith('+00:00')
            ->and($body['cnpj_tomador'])->toBe($document->invoice->entity->national_registration);
    });
});

describe('erros da Focus', function () {
    it('422 de validação: erro_autorizacao com os erros da Focus, sem retentativa, e avisa o time', function () {
        Http::fake(['*' => Http::response(['codigo' => 'erro_validacao', 'mensagem' => 'Inscrição municipal inválida', 'correcao' => 'Confira o cadastro'], 422)]);

        nfseRunSend($this->document); // não lança: repetir daria o mesmo erro

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::AuthorizationError)
            ->and($document->errors)->toEqual([['codigo' => 'erro_validacao', 'mensagem' => 'Inscrição municipal inválida', 'correcao' => 'Confira o cadastro']]);

        $log = BillingLog::query()->where('invoice_id', $this->invoice->id)->sole();

        expect($log->level)->toBe('warning')
            ->and($log->entity_id)->toBe($this->invoice->entity_id)
            ->and($log->context['codigos'])->toBe(['erro_validacao']);
    });

    it('400 de requisição inválida também é erro_autorizacao', function () {
        Http::fake(['*' => Http::response(['codigo' => 'requisicao_invalida', 'mensagem' => 'Parâmetro "prestador.codigo_municipio" não informado'], 400)]);

        nfseRunSend($this->document);

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::AuthorizationError)
            ->and($document->errors[0]['mensagem'])->toContain('prestador.codigo_municipio');
    });

    it('401 (token recusado): send_failed sem retentativa, com mensagem traduzida e alerta', function () {
        Http::fake(['*' => Http::response('HTTP Basic: Access denied', 401, ['Content-Type' => 'text/html'])]);

        nfseRunSend($this->document);

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::SendFailed)
            ->and($document->errors[0]['codigo'])->toBe('auth')
            ->and($document->errors[0]['mensagem'])->toBe(__('fiscal.errors.focus_auth'))
            ->and(BillingLog::query()->where('invoice_id', $this->invoice->id)->sole()->level)->toBe('warning');

        Http::assertSentCount(1);
    });

    it('send_failed pode ser reenviado depois de corrigir o token: mesma nota, mesma ref', function () {
        Http::fake(['*' => Http::sequence()->push('HTTP Basic: Access denied', 401)->push(['codigo' => 'nao_encontrado'], 404)->push(focusProcessing(), 201)]);

        nfseRunSend($this->document);
        expect($this->document->fresh()->status)->toBe(NfseStatus::SendFailed);

        nfseRunSend($this->document);

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::Processing)
            ->and($document->errors)->toBeNull()
            ->and($document->ref)->toBe($this->document->ref);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "ref={$this->document->ref}"));
    });

    it('sem credencial do ambiente: send_failed, sem chamar a Focus', function () {
        NfseCredential::query()->delete();

        Http::fake();

        nfseRunSend($this->document);

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::SendFailed)
            ->and($document->errors[0]['codigo'])->toBe('credencial_ausente');

        Http::assertNothingSent();
    });

    it('404 no envio (padrão não habilitado na empresa): send_failed', function () {
        Http::fake(['*' => Http::response(['codigo' => 'nao_encontrado', 'mensagem' => 'Recurso não encontrado'], 404)]);

        nfseRunSend($this->document);

        expect($this->document->fresh()->status)->toBe(NfseStatus::SendFailed);
    });

    it('422 nfe_autorizada: a Focus já tem a ref (resposta perdida antes) e a nota segue processando', function () {
        Http::fake(['*' => Http::response(['codigo' => 'nfe_autorizada', 'mensagem' => 'Nota fiscal já autorizada'], 422)]);

        nfseRunSend($this->document);

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::Processing)
            ->and($document->errors)->toBeNull();
    });
});

describe('queda e limite: tenta de novo', function () {
    it('5xx e queda de conexão lançam (o job tenta de novo) e a nota continua pending_send', function (string $failure) {
        Http::fake($failure === 'timeout'
            ? ['*' => fn () => throw new ConnectionException('cURL error 28: timeout')]
            : ['*' => Http::response('Bad Gateway', 502)]);

        expect(fn () => nfseRunSend($this->document))->toThrow(FocusNfeException::class);

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::PendingSend)
            ->and($document->request_payload)->not->toBeNull()
            ->and($document->errors)->toBeNull();
    })->with(['502', 'timeout']);

    it('a segunda tentativa reenvia O MESMO corpo, com a mesma ref e a mesma data, mesmo que a configuração tenha mudado', function () {
        Http::fake(['*' => Http::sequence()->push('Bad Gateway', 502)->push(['codigo' => 'nao_encontrado'], 404)->push(focusProcessing(), 201)]);

        expect(fn () => nfseRunSend($this->document))->toThrow(FocusNfeException::class);

        // Mudança de configuração entre as tentativas não pode alterar uma nota em voo.
        (new NfseSettings())->save(['prestador' => ['aliquota_iss' => 0.02, 'item_lista_servico' => '17.09']]);

        nfseRunSend($this->document);

        $bodies = nfseSentBodies();

        expect($bodies)->toHaveCount(2)
            ->and($bodies[1])->toEqual($bodies[0])
            ->and($bodies[1]['servico']['aliquota'])->toBe(0.05)
            ->and($bodies[1]['servico']['item_lista_servico'])->toBe('01.07')
            ->and($this->document->fresh()->status)->toBe(NfseStatus::Processing);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "ref={$this->document->ref}"));
    });

    it('no nacional o reenvio mantém série e número da DPS', function () {
        (new NfseSettings())->save(['standard' => 'nacional', 'prestador' => ['proximo_numero_dps' => 500]]);

        $document = app(NfseIssueService::class)->issue(nfsePaidInvoice(), 'auto');

        Http::fake(['*' => Http::sequence()->push('Bad Gateway', 502)->push(['codigo' => 'nao_encontrado'], 404)->push(['status' => 'processando_autorizacao'], 202)]);

        expect(fn () => nfseRunSend($document))->toThrow(FocusNfeException::class);

        nfseRunSend($document);

        $bodies = nfseSentBodies();

        expect($bodies[0]['numero_dps'])->toBe(500)->and($bodies[1]['numero_dps'])->toBe(500)
            ->and((new NfseSettings())->get(NfseSettings::COUNTER_KEY))->toBe(501);
    });

    it('429 com Retry-After devolve o job à fila pelo tempo pedido, sem gastar o backoff', function () {
        Http::fake(['*' => Http::response(['codigo' => 'limite', 'mensagem' => 'Muitas requisições'], 429, ['Retry-After' => '45'])]);

        $job = new SendNfseJob($this->document->id);
        $job->withFakeQueueInteractions();

        $job->handle(app(NfseSendService::class));

        $job->assertReleased(45);

        expect($this->document->fresh()->status)->toBe(NfseStatus::PendingSend);
    });

    it('429 sem Retry-After lança, para o backoff do job valer', function () {
        Http::fake(['*' => Http::response(['codigo' => 'limite'], 429)]);

        expect(fn () => nfseRunSend($this->document))->toThrow(FocusNfeException::class);
    });

    it('esgotadas as tentativas, failed() marca send_failed e avisa o time', function () {
        $job = new SendNfseJob($this->document->id);

        $job->failed(FocusNfeException::connection());

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::SendFailed)
            ->and($document->errors[0]['codigo'])->toBe('send_failed')
            ->and($document->errors[0]['mensagem'])->toBe(__('fiscal.errors.focus_unavailable'))
            ->and(BillingLog::query()->where('invoice_id', $this->invoice->id)->sole()->level)->toBe('error');
    });

    it('failed() com erro inesperado também marca send_failed, sem vazar a mensagem da exceção', function () {
        (new SendNfseJob($this->document->id))->failed(new RuntimeException('SQLSTATE senha=segredo'));

        $document = $this->document->fresh();

        expect($document->status)->toBe(NfseStatus::SendFailed)
            ->and(json_encode($document->errors))->not->toContain('segredo');
    });

    it('configura 5 tentativas com backoff de 1 min a 6 h, na fila fiscal', function () {
        config(['fiscal.queue' => 'fiscal']);

        $job = new SendNfseJob('x');

        expect($job->tries)->toBe(5)
            ->and($job->backoff())->toBe([60, 300, 1800, 3600, 21600])
            ->and($job->queue)->toBe('fiscal');
    });
});

describe('idempotência', function () {
    it('nota que já saiu de pending_send/send_failed não é reenviada', function (NfseStatus $status) {
        $this->document->update(['status' => $status->value]);

        Http::fake();

        nfseRunSend($this->document);

        Http::assertNothingSent();

        expect($this->document->fresh()->status)->toBe($status);
    })->with([NfseStatus::Processing, NfseStatus::Authorized, NfseStatus::AuthorizationError, NfseStatus::Cancelled]);

    it('nota inexistente é ignorada', function () {
        Http::fake();

        (new SendNfseJob('00000000-0000-0000-0000-000000000000'))->handle(app(NfseSendService::class));

        Http::assertNothingSent();
    });

    it('o webhook que chegou antes da resposta não é desfeito por ela', function () {
        $document = $this->document;

        // A Focus autoriza e notifica ENQUANTO a resposta do POST ainda não voltou.
        Http::fake(['*' => function () use ($document) {
            NfseDocument::query()->whereKey($document->id)->update(['status' => NfseStatus::Authorized->value, 'numero' => '123']);

            return Http::response(focusProcessing(), 201);
        }]);

        nfseRunSend($document);

        $fresh = $document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Authorized)
            ->and($fresh->numero)->toBe('123')
            ->and($fresh->response_payload)->toBeNull();
    });

    it('duas execuções seguidas só enviam uma vez', function () {
        Http::fake(['*' => Http::response(focusProcessing(), 201)]);

        nfseRunSend($this->document);
        nfseRunSend($this->document);

        Http::assertSentCount(1);
    });
});

describe('segredo', function () {
    it('o token nunca vai ao corpo, ao banco, ao log de cobrança nem à auditoria', function () {
        $logged = [];

        foreach (['info', 'warning', 'error'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(function (...$args) use (&$logged) {
                $logged[] = json_encode($args);
            });
        }

        // A Focus (hostil) devolve um campo de credencial no corpo e um erro: nada disso pode ser guardado.
        Http::fake(['*' => Http::response(focusProcessing() + ['token' => 'tok-empresa-homologacao-SEGREDO', 'authorization' => 'Basic xyz'], 201)]);

        nfseRunSend($this->document);

        $document = $this->document->fresh();
        $stored   = json_encode([$document->request_payload, $document->response_payload, $document->errors]);

        expect($stored)->not->toContain('SEGREDO')->not->toContain('Basic')
            ->and($document->response_payload)->not->toHaveKeys(['token', 'authorization']);

        $audits = AuditLog::query()->where('auditable_type', NfseDocument::class)->get()->map(fn ($a) => json_encode([$a->old_values, $a->new_values]))->implode(' ');

        expect($audits)->not->toContain('SEGREDO');

        foreach ($logged as $line) {
            expect($line)->not->toContain('SEGREDO');
        }
    });

    it('o token também não aparece quando a Focus recusa (log de cobrança e erros)', function () {
        Http::fake(['*' => Http::response(['codigo' => 'erro_validacao', 'mensagem' => 'Campo inválido'], 422)]);

        nfseRunSend($this->document);

        $dump = json_encode([$this->document->fresh()->errors, BillingLog::query()->get()->map->context->all()]);

        expect($dump)->not->toContain('SEGREDO');
    });
});
