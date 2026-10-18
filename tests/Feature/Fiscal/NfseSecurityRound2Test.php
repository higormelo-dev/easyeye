<?php

use App\Enums\Fiscal\NfseStatus;
use App\Jobs\Fiscal\{ProcessNfseWebhookJob, SendNfseJob};
use App\Models\Billing\BillingLog;
use App\Models\{Entity, User};
use App\Models\Fiscal\{NfseCredential, NfseDocument};
use App\Services\Fiscal\{NfseCancellationService, NfseSyncService};
use App\Services\Fiscal\NfseSendService;
use App\Support\Fiscal\{NfseLogText, NfseUrlGuard};
use App\Support\Fiscal\NfseManagerPresenter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Http, Log, Queue, Storage};

require_once __DIR__ . '/Support/NfseFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();
    $this->saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
    nfseReady();
});

describe('S1 — credenciais nunca voltam pela sessão', function () {
    it('a validação que falha numa requisição HTML não coloca token, master nem segredo na sessão', function () {
        $secret = 'tok-da-empresa-SEGREDO-S1';

        $response = nfseManager('admin')->from('/panel/manager/nfse')->post(route('manager.nfse.credentials.store', 'homologacao'), [
            'token' => $secret, 'master_token' => 'tok-master-SEGREDO-S1', 'webhook_secret' => 'wh-SEGREDO-S1', 'reason' => 'curto',
        ]);

        $response->assertRedirect('/panel/manager/nfse')->assertSessionHasErrors(['reason']);

        $old = session()->get('_old_input', []);

        expect($old)->not->toHaveKeys(['token', 'master_token', 'webhook_secret'])
            ->and(json_encode(session()->all()))->not->toContain('SEGREDO-S1');
    });

    it('o handler de exceções esquece os três campos', function () {
        $dontFlash = (fn () => $this->dontFlash)->call(app(ExceptionHandler::class));

        expect($dontFlash)->toContain('token')->toContain('master_token')->toContain('webhook_secret')->toContain('password');
    });
});

describe('S2 — links da Focus só em https nos hosts dela', function () {
    function nfseSyncWith(array $extra): NfseDocument
    {
        $document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Processing->value]);

        Http::fake(['*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        app(NfseSyncService::class)->apply($document->ref, $document->environment, array_replace(focusAuthorized(), ['ref' => $document->ref], $extra), 'webhook');

        return $document->fresh();
    }

    it('descarta (e loga sem a URL) o que não é https nos hosts permitidos; guarda o resto da nota', function (string $field, string $bad, string $column) {
        Log::spy();

        $document = nfseSyncWith([$field => $bad]);

        expect($document->status)->toBe(NfseStatus::Authorized)
            ->and($document->numero)->toBe('1')
            ->and($document->{$column})->toBeNull()
            ->and($document->response_payload)->not->toHaveKey($field);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'nfse.sync.url_rejected' && $context['field'] === $field && ! str_contains(json_encode($context), $bad))->once();
    })->with([
        'javascript'       => ['url', 'javascript:alert(document.cookie)', 'url'],
        'data'             => ['url_danfse', 'data:text/html;base64,PHNjcmlwdD4=', 'pdf_url'],
        'http'             => ['url', 'http://api.focusnfe.com.br/notas/n.html', 'url'],
        'host estranho'    => ['url_danfse', 'https://evil.test/n.pdf', 'pdf_url'],
        'sufixo enganoso'  => ['url', 'https://focusnfe.com.br.evil.test/n.html', 'url'],
        'prefixo enganoso' => ['url_danfse', 'https://evilfocusnfe.com.br/n.pdf', 'pdf_url'],
        'usuário na URL'   => ['url', 'https://api.focusnfe.com.br@evil.test/n.html', 'url'],
        'porta'            => ['url', 'https://api.focusnfe.com.br:8443/n.html', 'url'],
        'S3 de outro dono' => ['url_danfse', 'https://evil.s3.sa-east-1.amazonaws.com/n.pdf', 'pdf_url'],
    ]);

    it('aceita os links reais da Focus e da consulta pública nacional', function () {
        $document = nfseSyncWith(['url' => 'https://www.nfse.gov.br/consultapublica/?tpc=1&chave=42054072212345678000123']);

        expect($document->url)->toBe('https://www.nfse.gov.br/consultapublica/?tpc=1&chave=42054072212345678000123')
            ->and($document->pdf_url)->toBe('https://focusnfe.s3.sa-east-1.amazonaws.com/arquivos/12345678901_123/202501/DANFSEs/chave_nfse.pdf');

        expect(nfseSyncWith([])->url)->toBe('https://api.focusnfe.com.br/notas_fiscais_servico/chave_nfse.html');
    });

    it('caminho de XML fora de /arquivos/ (ou com ..) é descartado e nada é baixado', function (string $path) {
        $document = nfseSyncWith(['caminho_xml_nota_fiscal' => $path]);

        expect($document->response_payload)->not->toHaveKey('caminho_xml_nota_fiscal')->and($document->xml_path)->toBeNull();
        Http::assertNothingSent();
    })->with(['/etc/passwd', '/arquivos/../v2/empresas', 'https://evil.test/arquivos/x.xml', '//evil.test/arquivos/x.xml', 'arquivos/x.xml']);

    it('a lista de hosts vem da configuração', function () {
        config(['fiscal.allowed_url_hosts' => ['so.este.host']]);

        expect(NfseUrlGuard::isAllowedUrl('https://so.este.host/x'))->toBeTrue()
            ->and(NfseUrlGuard::isAllowedUrl('https://api.focusnfe.com.br/x'))->toBeFalse()
            ->and(NfseUrlGuard::isAllowedUrl(null))->toBeFalse()
            ->and(NfseUrlGuard::isAllowedUrl(['https://so.este.host']))->toBeFalse();
    });
});

describe('S3 — texto livre da Focus nunca entra no log de cobrança', function () {
    function nfseCancelFailingWith(array $errors): BillingLog
    {
        Http::fake(['*' => fn (Request $r) => $r->method() === 'DELETE'
            ? Http::response(['status' => 'erro_cancelamento', 'erros' => $errors], 200)
            : Http::response('x', 500)]);

        $document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Authorized->value, 'request_payload' => ['prestador' => ['codigo_municipio' => '3550308']]]);

        app(NfseCancellationService::class)->forReversal($document, 'refund');

        return BillingLog::query()->where('invoice_id', $document->invoice_id)->sole();
    }

    it('a pendência de cancelamento grava motivo FIXO e traduzível mais um código permitido, nada do texto da Focus', function () {
        $hostile = "Titular maria@clinica.test CPF 123.456.789-09 recusado\r\n[2026-10-18] CRITICAL falsa linha \x1b[31m\x00" . str_repeat('X', 600);

        $log  = nfseCancelFailingWith([['codigo' => 'V999', 'mensagem' => $hostile]]);
        $dump = json_encode([$log->message, $log->context], JSON_UNESCAPED_UNICODE);

        expect($log->message)->toBe('NFS-e pendente de cancelamento: ' . trans('fiscal.cancel_pending.focus_error', [], 'pt_BR'))
            ->and($log->context['cause'])->toBe('focus_error')
            ->and($log->context['code'])->toBe('V999')
            ->and($dump)->not->toContain('maria@')->not->toContain('123.456')->not->toContain('CRITICAL')->not->toContain('XXXX')
            ->and($log->message)->not->toMatch('/[\x00-\x1F\x7F]/');
    });

    it('código da Focus fora da allowlist e do padrão de prefeitura vira "unknown" no log de cobrança', function () {
        $log = nfseCancelFailingWith([['codigo' => '12345678909', 'mensagem' => 'x']]);

        expect($log->context['code'])->toBe('unknown')->and(json_encode($log->context))->not->toContain('12345678909');
    });

    it('NfseLogText::code: só código conhecido (allowlist exata) ou de prefeitura (^[A-Z]{1,3}[0-9]{1,4}$); o resto é "unknown"', function (string $given, string $expected) {
        expect(NfseLogText::code($given))->toBe($expected);
    })->with([
        'CPF de 11 dígitos'          => ['12345678909', 'unknown'],
        'token de 32 alfanuméricos'  => ['a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6', 'unknown'],
        'quatro letras'              => ['ABCD1', 'unknown'],
        'cinco dígitos'              => ['L12345', 'unknown'],
        'minúscula'                  => ['l99', 'unknown'],
        'e-mail'                     => ['maria@clinica.test', 'unknown'],
        'script'                     => ['<script>', 'unknown'],
        'quebra de linha'            => ["L99\nFAKE", 'unknown'],
        'prefeitura L99'             => ['L99', 'L99'],
        'prefeitura V999'            => ['V999', 'V999'],
        'prefeitura E0014'           => ['E0014', 'E0014'],
        'prefeitura 3 letras'        => ['ABC1234', 'ABC1234'],
        'documentado erro_validacao' => ['erro_validacao', 'erro_validacao'],
        'documentado nfe_nao_aut.'   => ['nfe_nao_autorizada', 'nfe_nao_autorizada'],
        'documentado permissao'      => ['permissao_negada', 'permissao_negada'],
        'interno credencial_ausente' => ['credencial_ausente', 'credencial_ausente'],
        'vazio'                      => ['', ''],
        'só espaços'                 => ['   ', ''],
    ]);

    it('todos os códigos documentados da Focus estão na lista de manutenção (config fiscal.log_codes)', function () {
        foreach (['requisicao_invalida', 'erro_validacao', 'nfe_autorizada', 'nfe_nao_autorizada', 'nao_encontrado', 'erro_autorizacao', 'erro_cancelamento', 'permissao_negada', 'auth', 'rate_limited', 'http', 'nfse_nao_autorizada'] as $code) {
            expect(config('fiscal.log_codes'))->toContain($code);
        }
    });

    it('envio recusado com "codigo" de CPF/token: nem o billing_logs nem o Log do aviso o recebem', function () {
        Log::spy();

        $document = nfseDocument(nfsePaidInvoice());

        Http::fake(['*' => Http::response(['codigo' => '12345678909', 'mensagem' => 'x'], 422)]);

        (new SendNfseJob($document->id))->handle(app(NfseSendService::class));

        $log = BillingLog::query()->where('invoice_id', $document->invoice_id)->sole();

        expect($log->context['codigos'])->toBe(['unknown']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'nfse.send' && $context['codigos'] === ['unknown'] && ! str_contains(json_encode($context), '12345678909'));
    });

    it('envio recusado: a mensagem da Focus (com e-mail/CPF) fica SÓ em nfse_documents.errors, saneada; o log de cobrança só leva o código', function () {
        $document = nfseDocument(nfsePaidInvoice());

        Http::fake(['*' => Http::response(['codigo' => 'erro_validacao', 'mensagem' => "Tomador joao@dono.test CPF 111.222.333-44\r\ninválido"], 422)]);

        (new SendNfseJob($document->id))->handle(app(NfseSendService::class));

        $log = BillingLog::query()->where('invoice_id', $document->invoice_id)->sole();

        expect(json_encode([$log->message, $log->context]))->not->toContain('joao@')->not->toContain('111.222')
            ->and($log->context['codigos'])->toBe(['erro_validacao'])
            ->and($document->fresh()->errors[0]['mensagem'])->toBe('Tomador joao@dono.test CPF 111.222.333-44 inválido');
    });

    it('os outros motivos de pendência também são fixos', function () {
        nfseCacheMunicipality(['possui_cancelamento_nfse' => false]);
        Http::fake();

        $document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Authorized->value, 'request_payload' => ['prestador' => ['codigo_municipio' => '3550308']]]);
        app(NfseCancellationService::class)->forReversal($document, 'refund');

        expect(BillingLog::query()->where('invoice_id', $document->invoice_id)->sole()->message)->toBe('NFS-e pendente de cancelamento: ' . trans('fiscal.cancel_pending.no_webservice', [], 'pt_BR'));
    });

    it('NfseLogText limpa controle, espaços e tamanho; code() deixa só caracteres de código', function () {
        expect(NfseLogText::clean("a\r\nb\tc\x00d  e"))->toBe('a b c d e')
            ->and(mb_strlen(NfseLogText::clean(str_repeat('é', 500))))->toBe(200)
            ->and(NfseLogText::clean(str_repeat('é', 50), 10))->toBe(str_repeat('é', 10))
            ->and(NfseLogText::code("L99\n<script>"))->toBe('unknown');
    });
});

describe('S2b — link já gravado com URL ruim nunca vira href', function () {
    it('Manager e clínica recebem null no lugar de javascript:/host estranho (linha persistida direto no banco)', function () {
        nfseManager('admin'); // sessão do Manager: a linha do Manager confere o Gate das ações
        $clinic   = nfseClinic();
        $invoice  = nfsePaidInvoice($clinic);
        $document = nfseDocument($invoice, ['status' => NfseStatus::Authorized->value, 'numero' => '9', 'pdf_url' => 'javascript:alert(1)', 'url' => 'https://evil.test/n.html']);

        $row = app(NfseManagerPresenter::class)->row($document);

        expect($row['urls']['pdf'])->toBeNull()->and($row['urls']['mirror'])->toBeNull();

        $block = app(NfseManagerPresenter::class)->clinicBlocks(collect([$invoice]))[(string) $invoice->id];

        expect($block['pdf_url'])->toBeNull()->and($block['mirror_url'])->toBeNull()->and($block['number'])->toBe('9');

        // E pela rota real da clínica (Minha assinatura → resumo).
        $user = User::factory()->create();
        $link = createEntityUser($clinic, $user, 'admin');

        $invoiceRow = collect($this->actingAs($user)->withSession(panelSession($link))->getJson(route('panel.my-subscription.summary'))->json('data.invoices'))->firstWhere('id', $document->invoice_id);

        expect($invoiceRow['nfse']['pdf_url'])->toBeNull()->and($invoiceRow['nfse']['mirror_url'])->toBeNull();
    });

    it('link bom continua saindo', function () {
        nfseManager('admin');
        $document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Authorized->value, 'pdf_url' => 'https://focusnfe.s3.sa-east-1.amazonaws.com/n.pdf', 'url' => 'https://api.focusnfe.com.br/n.html']);

        $row = app(NfseManagerPresenter::class)->row($document);

        expect($row['urls']['pdf'])->toBe('https://focusnfe.s3.sa-east-1.amazonaws.com/n.pdf')->and($row['urls']['mirror'])->toBe('https://api.focusnfe.com.br/n.html');
    });
});

describe('S4 — URL do gatilho só para quem configura', function () {
    it('o financeiro não recebe hook_urls nem a URL das credenciais; o admin recebe', function () {
        $token = NfseCredential::query()->sole()->webhook_token;

        $financial = nfseManager('financial')->get(route('manager.nfse.index'));
        $props     = $financial->viewData('page')['props'];

        expect($props['hook_urls'])->toBe(['homologacao' => null, 'producao' => null])
            ->and($props['credentials'][0]['hook']['url'])->toBeNull()
            ->and(json_encode($props))->not->toContain($token);

        $admin = nfseManager('admin')->get(route('manager.nfse.index'))->viewData('page')['props'];

        expect($admin['hook_urls']['homologacao'])->toContain($token)
            ->and($admin['credentials'][0]['hook']['url'])->toContain($token);
    });
});

describe('S5 — ref e status validados antes da fila', function () {
    function nfseHookPost(array $payload)
    {
        $credential = NfseCredential::query()->sole();

        return test()->postJson(route('fiscal.focusnfe.webhook', ['token' => $credential->webhook_token]), $payload, ['X-FocusNfe-Secret' => $credential->webhook_secret]);
    }

    it('ref malformada: 200, nada na fila e o valor recebido NÃO vai ao log', function (mixed $ref) {
        Queue::fake();
        Log::spy();

        nfseHookPost(['ref' => $ref, 'status' => 'autorizado'])->assertOk();

        Queue::assertNothingPushed();
        Log::shouldNotHaveReceived('info', fn (string $message, array $context = []) => str_contains(json_encode($context), 'script'));
    })->with([
        'hífen'     => 'ABC-123',
        'longa'     => str_repeat('A', 41),
        'crlf'      => "ABC\r\nFAKE LOG",
        'html'      => '<script>alert(1)</script>',
        'espaço'    => 'ABC 123',
        'acentuada' => 'REFEREÊNCIA',
        'ponto'     => 'A.B',
    ]);

    it('aceita ref alfanumérica de 1 a 40 caracteres', function (string $ref) {
        Queue::fake();

        nfseHookPost(['ref' => $ref, 'status' => 'autorizado'])->assertOk();

        Queue::assertPushed(ProcessNfseWebhookJob::class, fn (ProcessNfseWebhookJob $job) => $job->ref === $ref);
    })->with(['A', 'a1B2', str_repeat('F', 32), str_repeat('Z', 40)]);

    it('status fora dos da Focus (ausente, lixo, estado nosso, não texto): 200, sem fila, sem o valor no log', function (mixed $status) {
        Queue::fake();
        Log::spy();

        nfseHookPost(['ref' => 'ABC123', 'status' => $status])->assertOk();

        Queue::assertNothingPushed();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'nfse.webhook.invalid_status' && ! str_contains(json_encode($context), 'LIXO'))->once();
    })->with(['LIXO-MALICIOSO', 'pending_send', 'substituida', '', 123, 'lista' => [['autorizado']]]);

    it('os cinco status da Focus seguem para a fila', function (string $status) {
        Queue::fake();

        nfseHookPost(['ref' => 'ABC123', 'status' => $status])->assertOk();

        Queue::assertPushed(ProcessNfseWebhookJob::class, 1);
    })->with(['processando_autorizacao', 'autorizado', 'erro_autorizacao', 'cancelado', 'negado']);
});
