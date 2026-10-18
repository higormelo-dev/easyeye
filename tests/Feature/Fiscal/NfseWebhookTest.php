<?php

use App\Enums\Fiscal\{NfseEnvironment, NfseStatus};
use App\Jobs\Fiscal\ProcessNfseWebhookJob;
use App\Models\AuditLog;
use App\Models\Fiscal\{NfseCredential, NfseDocument};
use App\Support\Fiscal\NfseSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Http, Log, Queue, RateLimiter, Storage};

require_once __DIR__ . '/Support/NfseFixtures.php';

const NFSE_SECRET_HEADER = 'X-FocusNfe-Secret';

/** POST do webhook como a Focus faz: JSON, token na URL e o segredo no cabeçalho combinado. */
function nfseHook(NfseCredential $credential, array $payload, ?string $secret = null, ?string $token = null)
{
    $headers = $secret === '' ? [] : [NFSE_SECRET_HEADER => $secret ?? $credential->webhook_secret];

    return test()->postJson(route('fiscal.focusnfe.webhook', ['token' => $token ?? $credential->webhook_token]), $payload, $headers);
}

/** Aviso de nota autorizada, no formato da consulta da Focus. */
function nfseAuthorizedPayload(NfseDocument $document, array $extra = []): array
{
    return array_replace(focusAuthorized(), ['ref' => $document->ref, 'numero' => '77', 'codigo_verificacao' => 'XYZ789'], $extra);
}

function nfseAuditCount(NfseDocument $document): int
{
    return AuditLog::query()->where('auditable_type', NfseDocument::class)->where('auditable_id', $document->id)->count();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();
    nfseReady();

    $this->credential = NfseCredential::query()->where('environment', 'homologacao')->sole();
    $this->document   = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Processing->value]);
});

describe('autenticação', function () {
    it('token desconhecido: 404 genérico, nada vai para a fila', function () {
        Queue::fake();

        nfseHook($this->credential, ['ref' => $this->document->ref, 'status' => 'autorizado'], token: 'token-que-nao-existe')->assertNotFound();

        Queue::assertNothingPushed();
    });

    it('segredo errado ou ausente: 401, nada vai para a fila', function (?string $secret) {
        Queue::fake();

        nfseHook($this->credential, ['ref' => $this->document->ref, 'status' => 'autorizado'], secret: $secret)->assertUnauthorized();

        Queue::assertNothingPushed();
    })->with(['errado' => 'segredo-errado', 'vazio' => '', 'parecido' => 'wh-secret']);

    it('o segredo de um ambiente não vale no outro', function () {
        Queue::fake();

        $prod = nfseCredential(NfseEnvironment::Production);

        nfseHook($prod, ['ref' => 'X', 'status' => 'autorizado'], secret: $this->credential->webhook_secret)->assertUnauthorized();
        nfseHook($this->credential, ['ref' => 'X', 'status' => 'autorizado'], secret: $prod->webhook_secret)->assertUnauthorized();

        Queue::assertNothingPushed();
    });

    it('token certo e segredo certo: 200 imediato e o processamento vai para a fila, no ambiente do token', function () {
        Queue::fake();

        $prod = nfseCredential(NfseEnvironment::Production);

        nfseHook($this->credential, ['ref' => 'REFH', 'status' => 'autorizado'])->assertOk()->assertExactJson(['ok' => true]);
        nfseHook($prod, ['ref' => 'REFP', 'status' => 'cancelado'])->assertOk();

        Queue::assertPushed(ProcessNfseWebhookJob::class, fn (ProcessNfseWebhookJob $job) => $job->ref === 'REFH' && $job->environment === 'homologacao');
        Queue::assertPushed(ProcessNfseWebhookJob::class, fn (ProcessNfseWebhookJob $job) => $job->ref === 'REFP' && $job->environment === 'producao');
    });

    it('não grava nada na requisição: o estado só muda quando o job roda', function () {
        Queue::fake();

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        expect($this->document->fresh()->status)->toBe(NfseStatus::Processing);
    });

    it('corpo sem ref: 200 (a Focus não retenta lixo), sem job, e fica no log', function () {
        Queue::fake();
        Log::spy();

        nfseHook($this->credential, ['status' => 'autorizado'])->assertOk();
        nfseHook($this->credential, ['ref' => '  ', 'status' => 'autorizado'])->assertOk();
        nfseHook($this->credential, ['ref' => ['x'], 'status' => 'autorizado'])->assertOk();

        Queue::assertNothingPushed();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => $message === 'nfse.webhook.without_ref')->times(3);
    });

    it('chave de segredo no corpo não vai para a fila', function () {
        Queue::fake();

        nfseHook($this->credential, ['ref' => 'R1', 'status' => 'autorizado', 'token' => 'abc', 'Authorization' => 'Basic x', 'numero' => '1'])->assertOk();

        Queue::assertPushed(ProcessNfseWebhookJob::class, fn (ProcessNfseWebhookJob $job) => $job->payload === ['ref' => 'R1', 'status' => 'autorizado', 'numero' => '1']);
    });

    it('corpo gigante vai para a fila só com os campos conhecidos', function () {
        Queue::fake();

        nfseHook($this->credential, ['ref' => 'R1', 'status' => 'autorizado', 'numero' => '9', 'lixo' => str_repeat('x', 70000)])->assertOk();

        Queue::assertPushed(ProcessNfseWebhookJob::class, fn (ProcessNfseWebhookJob $job) => $job->payload === ['status' => 'autorizado', 'ref' => 'R1', 'numero' => '9'] || $job->payload === ['ref' => 'R1', 'status' => 'autorizado', 'numero' => '9']);
    });

    it('limita por token: o flood de token falso não derruba o balde da credencial verdadeira', function () {
        Queue::fake();
        config(['fiscal.webhook.unknown_rate_limit_per_minute' => 3]);
        RateLimiter::clear('fiscal-webhook:unknown');

        foreach (range(1, 3) as $i) {
            nfseHook($this->credential, ['ref' => 'X'], token: "falso-{$i}")->assertNotFound();
        }

        nfseHook($this->credential, ['ref' => 'X'], token: 'falso-4')->assertStatus(429);

        // Quem tem token e segredo certos não é afetado.
        nfseHook($this->credential, ['ref' => 'REFOK', 'status' => 'autorizado'])->assertOk();
    });

    it('segredo errado com token certo cai num balde pequeno próprio, e não consome o da credencial', function () {
        Queue::fake();

        foreach (range(1, 30) as $i) {
            nfseHook($this->credential, ['ref' => 'X'], secret: 'errado')->assertUnauthorized();
        }

        nfseHook($this->credential, ['ref' => 'X'], secret: 'errado')->assertStatus(429);
        nfseHook($this->credential, ['ref' => 'R1', 'status' => 'autorizado'])->assertOk();
    });
});

describe('aplicação (fila síncrona)', function () {
    it('processando → autorizado: grava número, código, data, links e baixa o XML', function () {
        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200, ['Content-Type' => 'application/xml'])]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        $fresh = $this->document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Authorized)
            ->and($fresh->numero)->toBe('77')
            ->and($fresh->codigo_verificacao)->toBe('XYZ789')
            ->and($fresh->issued_at->getTimestamp())->toBe(Carbon::parse('2025-01-01T10:00:00-03:00')->getTimestamp())
            ->and($fresh->url)->toBe('https://api.focusnfe.com.br/notas_fiscais_servico/chave_nfse.html')
            ->and($fresh->pdf_url)->toContain('DANFSEs/chave_nfse.pdf')
            ->and($fresh->response_payload['caminho_xml_nota_fiscal'])->toBe('/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse.xml')
            ->and($fresh->errors)->toBeNull()
            ->and($fresh->xml_path)->toBe("nfse/homologacao/{$fresh->created_at->format('Y/m')}/{$fresh->ref}.xml");

        expect(Storage::disk()->get($fresh->xml_path))->toBe(NFSE_XML);

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . '/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse.xml'
            && $request->header('Authorization') === ['Basic ' . base64_encode('tok-empresa-homologacao-SEGREDO:')]);
    });

    it('entrega repetida (mesma ref e status) não muda nada, não audita e não baixa o XML de novo', function () {
        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        $before = $this->document->fresh();
        $audits = nfseAuditCount($this->document);

        Http::fake();

        // Mesmo conteúdo, chaves em outra ordem (o jsonb do Postgres não guarda a ordem).
        nfseHook($this->credential, array_reverse(nfseAuthorizedPayload($this->document), true))->assertOk();
        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        $after = $this->document->fresh();

        expect($after->updated_at->equalTo($before->updated_at))->toBeTrue()
            ->and(nfseAuditCount($this->document))->toBe($audits)
            ->and($after->status)->toBe(NfseStatus::Authorized);

        Http::assertNothingSent();
    });

    it('a mudança de status entra na auditoria sem o corpo da nota', function () {
        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        $audit = AuditLog::query()->where('auditable_type', NfseDocument::class)->where('auditable_id', $this->document->id)->where('event', 'updated')->latest('created_at')->first();

        expect($audit->new_values)->toMatchArray(['status' => 'autorizado', 'numero' => '77'])
            ->and(json_encode($audit->new_values))->not->toContain('response_payload');
    });

    it('envia a cópia para o e-mail configurado UMA vez, na transição para autorizado', function () {
        (new NfseSettings())->save(['copy_email' => 'contador@dono.test']);

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200), '*/email' => Http::response(['emails' => ['contador@dono.test']], 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();
        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/email'));

        expect($sent)->toHaveCount(1)
            ->and($sent->first()[0]->data())->toBe(['emails' => ['contador@dono.test']])
            ->and($sent->first()[0]->url())->toBe(FOCUS_HOMOLOG . "/v2/nfse/{$this->document->ref}/email");
    });

    it('sem e-mail de cópia configurado (ou inválido) não manda nada; falha do e-mail não desfaz a nota', function () {
        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/email'));

        $other = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Processing->value]);
        (new NfseSettings())->save(['copy_email' => 'contador@dono.test']);
        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200), '*/email' => Http::response('Bad Gateway', 502)]);

        nfseHook($this->credential, nfseAuthorizedPayload($other))->assertOk();

        expect($other->fresh()->status)->toBe(NfseStatus::Authorized);
    });

    it('padrão nacional: o e-mail de cópia usa /v2/nfsen', function () {
        (new NfseSettings())->save(['copy_email' => 'contador@dono.test']);

        $national = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Processing->value, 'standard' => 'nacional']);

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200), '*/email' => Http::response([], 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($national))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . "/v2/nfsen/{$national->ref}/email");
    });

    it('XML que falha ao baixar não desfaz a nota; a próxima entrega completa o arquivo', function () {
        Http::fake(['*/arquivos/*' => Http::sequence()->push('Bad Gateway', 502)->push(NFSE_XML, 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        $fresh = $this->document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Authorized)->and($fresh->xml_path)->toBeNull();

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        expect($this->document->fresh()->xml_path)->not->toBeNull();
    });

    it('autorizado → cancelado: grava a data do cancelamento e baixa o XML do cancelamento', function () {
        $this->document->update(['status' => NfseStatus::Authorized->value, 'numero' => '77']);

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        nfseHook($this->credential, [
            'ref'                     => $this->document->ref, 'status' => 'cancelado', 'numero' => '77',
            'caminho_xml_nota_fiscal' => '/arquivos/1/n.xml', 'caminho_xml_cancelamento' => '/arquivos/1/n-can.xml',
        ])->assertOk();

        $fresh = $this->document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Cancelled)
            ->and($fresh->cancelled_at)->not->toBeNull()
            ->and($fresh->cancelled_externally)->toBeFalse()
            ->and($fresh->cancel_xml_path)->toEndWith('-cancel.xml')
            ->and($fresh->xml_path)->not->toBeNull();
    });

    it('erro_autorizacao: grava os erros da prefeitura, campo a campo', function () {
        nfseHook($this->credential, [
            'ref'   => $this->document->ref, 'status' => 'erro_autorizacao',
            'erros' => [['codigo' => 'L99', 'mensagem' => 'Regime Especial de Tributação ausente/inválido', 'correcao' => 'Corrija o Regime Especial de Tributação']],
        ])->assertOk();

        $fresh = $this->document->fresh();

        expect($fresh->status)->toBe(NfseStatus::AuthorizationError)
            ->and($fresh->errors)->toEqual([['codigo' => 'L99', 'mensagem' => 'Regime Especial de Tributação ausente/inválido', 'correcao' => 'Corrija o Regime Especial de Tributação']]);

        Http::assertNothingSent();
    });

    it('"negado" do padrão nacional é o mesmo desfecho; sem lista de erros ainda deixa um motivo', function () {
        nfseHook($this->credential, ['ref' => $this->document->ref, 'status' => 'negado'])->assertOk();

        $fresh = $this->document->fresh();

        expect($fresh->status)->toBe(NfseStatus::AuthorizationError)
            ->and($fresh->errors)->toHaveCount(1)
            ->and($fresh->errors[0]['codigo'])->toBe('erro_autorizacao');
    });

    it('pending_send e send_failed também avançam (o webhook chegou antes da resposta do envio)', function (NfseStatus $from) {
        $this->document->update(['status' => $from->value]);

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document))->assertOk();

        expect($this->document->fresh()->status)->toBe(NfseStatus::Authorized);
    })->with([NfseStatus::PendingSend, NfseStatus::SendFailed]);

    it('só avança: aviso fora de ordem ou de estado final é ignorado', function (NfseStatus $current, string $incoming) {
        $this->document->update(['status' => $current->value, 'numero' => '77']);

        nfseHook($this->credential, ['ref' => $this->document->ref, 'status' => $incoming, 'numero' => '999', 'erros' => [['mensagem' => 'x']]])->assertOk();

        $fresh = $this->document->fresh();

        expect($fresh->status)->toBe($current)->and($fresh->numero)->toBe('77');
    })->with([
        'autorizado ← processando' => [NfseStatus::Authorized, 'processando_autorizacao'],
        'autorizado ← erro'        => [NfseStatus::Authorized, 'erro_autorizacao'],
        'cancelado ← autorizado'   => [NfseStatus::Cancelled, 'autorizado'],
        'cancelado ← processando'  => [NfseStatus::Cancelled, 'processando_autorizacao'],
        'erro ← autorizado'        => [NfseStatus::AuthorizationError, 'autorizado'],
        'erro ← cancelado'         => [NfseStatus::AuthorizationError, 'cancelado'],
    ]);

    it('status desconhecido (a Focus cria valor novo) é ignorado sem erro', function () {
        nfseHook($this->credential, ['ref' => $this->document->ref, 'status' => 'substituida'])->assertOk();

        expect($this->document->fresh()->status)->toBe(NfseStatus::Processing);
    });

    it('ref desconhecida: 200, log, nada alterado; ref de outro ambiente também não casa', function () {
        Log::spy();

        $prod = nfseCredential(NfseEnvironment::Production);

        nfseHook($this->credential, ['ref' => 'NAOEXISTE', 'status' => 'autorizado'])->assertOk();
        nfseHook($prod, nfseAuthorizedPayload($this->document))->assertOk(); // ref da homologação, aviso da produção

        expect($this->document->fresh()->status)->toBe(NfseStatus::Processing);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => $message === 'nfse.sync.unknown_ref')->times(2);
    });

    it('campo de segredo que a Focus ecoe no corpo não é guardado', function () {
        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($this->document, ['token' => 'tok-vazado', 'authorization' => 'Basic zzz']))->assertOk();

        expect(json_encode($this->document->fresh()->response_payload))->not->toContain('tok-vazado')->not->toContain('zzz');
    });

    it('nota velha que a Focus resolveu depois não vira segunda nota valendo na mesma fatura', function () {
        $invoice = nfsePaidInvoice();
        $old     = nfseDocument($invoice, ['status' => NfseStatus::SendFailed->value]);
        $new     = nfseDocument($invoice, ['status' => NfseStatus::PendingSend->value]);

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        nfseHook($this->credential, nfseAuthorizedPayload($old))->assertOk();

        expect($old->fresh()->status)->toBe(NfseStatus::SendFailed)
            ->and($new->fresh()->status)->toBe(NfseStatus::PendingSend);
    });
});
