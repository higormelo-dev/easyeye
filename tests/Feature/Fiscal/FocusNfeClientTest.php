<?php

use App\Enums\Fiscal\{NfseEnvironment, NfseStandard};
use App\Exceptions\Fiscal\FocusNfeException;
use Illuminate\Http\Client\{ConnectionException, Request};
use Illuminate\Support\Facades\{Http, Log};

require_once __DIR__ . '/Support/NfseFixtures.php';

beforeEach(fn () => Http::preventStrayRequests());

describe('emissão', function () {
    it('pede a emissão municipal: POST /v2/nfse?ref= com o corpo JSON e o token em Basic (senha vazia)', function () {
        Http::fake([FOCUS_HOMOLOG . '/v2/nfse*' => Http::response(focusProcessing(), 201)]);

        $payload = ['data_emissao' => '2026-10-18T14:30:00-03:00', 'servico' => ['valor_servicos' => 100.0]];

        expect(focusClient()->issue(NfseStandard::Municipal, 'REF123', $payload))->toBe(focusProcessing());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === FOCUS_HOMOLOG . '/v2/nfse?ref=REF123'
            && $request->header('Authorization') === [focusBasic()]
            && $request->data() === $payload);
    });

    it('pede a emissão nacional em /v2/nfsen (202)', function () {
        Http::fake([FOCUS_HOMOLOG . '/v2/nfsen*' => Http::response(['cnpj_prestador' => '12345678000123', 'ref' => '123456', 'status' => 'processando_autorizacao'], 202)]);

        $response = focusClient()->issue(NfseStandard::National, 'REF456', ['serie_dps' => 1, 'numero_dps' => 7]);

        expect($response['status'])->toBe('processando_autorizacao');

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . '/v2/nfsen?ref=REF456');
    });

    it('usa o host de produção só no ambiente de produção', function () {
        Http::fake(['*' => Http::response(focusProcessing(), 201)]);

        focusClient(NfseEnvironment::Production)->issue(NfseStandard::Municipal, 'R1', []);
        focusClient(NfseEnvironment::Homologation)->issue(NfseStandard::Municipal, 'R2', []);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), FOCUS_PROD . '/v2/nfse?ref=R1'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), FOCUS_HOMOLOG . '/v2/nfse?ref=R2'));
    });

    it('trata 400 como pedido inválido, sem repetir', function () {
        Http::fake(['*' => Http::response(['codigo' => 'requisicao_invalida', 'mensagem' => 'Parâmetro "prestador.codigo_municipio" não informado'], 400)]);

        try {
            focusClient()->issue(NfseStandard::Municipal, 'R1', []);
            $this->fail('esperava FocusNfeException');
        } catch (FocusNfeException $e) {
            expect($e->errorCode)->toBe(FocusNfeException::INVALID_REQUEST)
                ->and($e->status)->toBe(400)
                ->and($e->retryable())->toBeFalse()
                ->and($e->errors)->toBe([['codigo' => 'requisicao_invalida', 'mensagem' => 'Parâmetro "prestador.codigo_municipio" não informado', 'correcao' => null]]);
        }
    });

    it('distingue erro de validação (422) de nota já autorizada (422)', function () {
        Http::fake([
            '*/v2/nfse?ref=VALID' => Http::response(['codigo' => 'erro_validacao', 'mensagem' => 'O emitente da nota fiscal é diferente do usado no primeiro envio'], 422),
            '*/v2/nfse?ref=AUTH'  => Http::response(['codigo' => 'nfe_autorizada', 'mensagem' => 'Nota fiscal já autorizada'], 422),
        ]);

        $validation = null;
        $authorized = null;

        try {
            focusClient()->issue(NfseStandard::Municipal, 'VALID', []);
        } catch (FocusNfeException $e) {
            $validation = $e;
        }

        try {
            focusClient()->issue(NfseStandard::Municipal, 'AUTH', []);
        } catch (FocusNfeException $e) {
            $authorized = $e;
        }

        expect($validation->errorCode)->toBe(FocusNfeException::VALIDATION)
            ->and($validation->retryable())->toBeFalse()
            ->and($authorized->errorCode)->toBe(FocusNfeException::ALREADY_AUTHORIZED)
            ->and($authorized->retryable())->toBeFalse();
    });

    it('lê o 401 em HTML ("HTTP Basic: Access denied") como token recusado, e o 403 também', function () {
        Http::fake([
            '*/v2/nfse?ref=A' => Http::response('HTTP Basic: Access denied', 401, ['Content-Type' => 'text/html']),
            '*/v2/nfse?ref=B' => Http::response(['codigo' => 'permissao_negada', 'mensagem' => 'Sem acesso'], 403),
        ]);

        foreach (['A', 'B'] as $ref) {
            try {
                focusClient()->issue(NfseStandard::Municipal, $ref, []);
                $this->fail('esperava FocusNfeException');
            } catch (FocusNfeException $e) {
                expect($e->errorCode)->toBe(FocusNfeException::AUTH)
                    ->and($e->retryable())->toBeFalse();
            }
        }
    });

    it('trata 429 como repetível e guarda a espera pedida', function () {
        Http::fake(['*' => Http::response(['codigo' => 'limite', 'mensagem' => 'Muitas requisições'], 429, ['Retry-After' => '45'])]);

        try {
            focusClient()->issue(NfseStandard::Municipal, 'R1', []);
            $this->fail('esperava FocusNfeException');
        } catch (FocusNfeException $e) {
            expect($e->errorCode)->toBe(FocusNfeException::RATE_LIMITED)
                ->and($e->retryable())->toBeTrue()
                ->and($e->inconclusive())->toBeTrue()
                ->and($e->retryAfter)->toBe(45);
        }
    });

    it('trata 5xx e queda de conexão como repetíveis e inconclusivos (a Focus pode ter recebido)', function () {
        Http::fake(['*/v2/nfse?ref=S5' => Http::response('Bad Gateway', 502)]);

        foreach (['S5' => null, 'NET' => new ConnectionException('cURL error 28: timeout')] as $ref => $failure) {
            if ($failure !== null) {
                Http::fake(['*' => fn () => throw $failure]);
            }

            try {
                focusClient()->issue(NfseStandard::Municipal, $ref, []);
                $this->fail('esperava FocusNfeException');
            } catch (FocusNfeException $e) {
                expect($e->errorCode)->toBe(FocusNfeException::UNAVAILABLE)
                    ->and($e->retryable())->toBeTrue()
                    ->and($e->inconclusive())->toBeTrue();
            }
        }
    });

    it('não acompanha redirecionamento (o token não pode ir a outro host)', function () {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.test/steal'])]);

        expect(fn () => focusClient()->issue(NfseStandard::Municipal, 'R1', []))->toThrow(FocusNfeException::class);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.test'));
    });
});

describe('consulta', function () {
    it('devolve a nota autorizada com os campos da Focus (e tolera campo novo)', function () {
        Http::fake(['*' => Http::response(focusAuthorized() + ['campo_novo_da_focus' => ['x' => 1]], 200)]);

        $note = focusClient()->show(NfseStandard::Municipal, '123456');

        expect($note['status'])->toBe('autorizado')
            ->and($note['numero'])->toBe('1')
            ->and($note['codigo_verificacao'])->toBe('ABC123')
            ->and($note['caminho_xml_nota_fiscal'])->toBe('/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse.xml')
            ->and($note['campo_novo_da_focus'])->toBe(['x' => 1]);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === FOCUS_HOMOLOG . '/v2/nfse/123456');
    });

    it('consulta o nacional em /v2/nfsen/{ref}', function () {
        Http::fake(['*' => Http::response(['cnpj_prestador' => '12345678000123', 'ref' => '123456', 'status' => 'processando_autorizacao'], 200)]);

        focusClient()->show(NfseStandard::National, '123456');

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . '/v2/nfsen/123456');
    });

    it('devolve os erros da nota recusada pela prefeitura', function () {
        Http::fake(['*' => Http::response([
            'cnpj_prestador' => '12345678000123', 'ref' => '123456', 'numero_rps' => '1', 'serie_rps' => '1', 'tipo_rps' => '1',
            'status'         => 'erro_autorizacao',
            'erros'          => [['codigo' => 'L99', 'mensagem' => 'Regime Especial de Tributação ausente/inválido', 'correcao' => 'Corrija o Regime Especial de Tributação']],
        ], 200)]);

        $note = focusClient()->show(NfseStandard::Municipal, '123456');

        expect($note['status'])->toBe('erro_autorizacao')
            ->and($note['erros'][0]['codigo'])->toBe('L99');
    });

    it('devolve null quando a Focus não conhece a ref (404)', function () {
        Http::fake(['*' => Http::response(['codigo' => 'nao_encontrado', 'mensagem' => 'Nota fiscal não encontrada'], 404)]);

        expect(focusClient()->show(NfseStandard::Municipal, 'NAOEXISTE'))->toBeNull();
    });

    it('não engole os outros erros da consulta', function () {
        Http::fake(['*' => Http::response('HTTP Basic: Access denied', 401, ['Content-Type' => 'text/html'])]);

        expect(fn () => focusClient()->show(NfseStandard::Municipal, 'R1'))->toThrow(FocusNfeException::class);
    });
});

describe('cancelamento', function () {
    it('cancela: DELETE com a justificativa no corpo e devolve {status: cancelado}', function () {
        Http::fake(['*' => Http::response(['status' => 'cancelado'], 200)]);

        $result = focusClient()->cancel(NfseStandard::Municipal, '123456', 'Estorno total da fatura INV-1');

        expect($result)->toBe(['status' => 'cancelado']);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === FOCUS_HOMOLOG . '/v2/nfse/123456'
            && $request->data() === ['justificativa' => 'Estorno total da fatura INV-1']);
    });

    it('cancela no nacional em /v2/nfsen/{ref}', function () {
        Http::fake(['*' => Http::response(['status' => 'cancelado'], 200)]);

        focusClient()->cancel(NfseStandard::National, '123456', 'Cancelamento a pedido do cliente');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === FOCUS_HOMOLOG . '/v2/nfsen/123456');
    });

    it('devolve erro_cancelamento como resultado (não como exceção)', function () {
        $body = ['status' => 'erro_cancelamento', 'erros' => [['codigo' => 'V999', 'mensagem' => 'NFSe fora do prazo de cancelamento permitido', 'correcao' => null]]];

        Http::fake(['*' => Http::response($body, 200)]);

        expect(focusClient()->cancel(NfseStandard::Municipal, '123456', 'Justificativa com mais de 15'))->toBe($body);
    });

    it('lança nfe_nao_autorizada quando a nota não está autorizada (400)', function () {
        Http::fake(['*' => Http::response(['codigo' => 'nfe_nao_autorizada', 'mensagem' => 'Nota fiscal não autorizada não pode ser cancelada'], 400)]);

        try {
            focusClient()->cancel(NfseStandard::Municipal, '123456', 'Justificativa com mais de 15');
            $this->fail('esperava FocusNfeException');
        } catch (FocusNfeException $e) {
            expect($e->errorCode)->toBe(FocusNfeException::NOT_AUTHORIZED)
                ->and($e->retryable())->toBeFalse();
        }
    });
});

describe('e-mail', function () {
    it('manda a cópia por e-mail para a lista sem repetidos', function () {
        Http::fake(['*' => Http::response(['emails' => ['a@x.test', 'b@x.test']], 200)]);

        focusClient()->email(NfseStandard::Municipal, '123456', ['a@x.test', ' b@x.test ', 'a@x.test', '']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === FOCUS_HOMOLOG . '/v2/nfse/123456/email'
            && $request->data() === ['emails' => ['a@x.test', 'b@x.test']]);
    });

    it('recusa lista vazia e mais de 10 destinatários antes de chamar', function () {
        Http::fake();

        expect(fn () => focusClient()->email(NfseStandard::Municipal, '1', []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => focusClient()->email(NfseStandard::Municipal, '1', array_map(fn ($i) => "u{$i}@x.test", range(1, 11))))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    });
});

describe('webhook (gatilho)', function () {
    it('registra o gatilho com o segredo no cabeçalho escolhido', function () {
        Http::fake(['*' => Http::response(['id' => 'Vj5rmkBq', 'url' => 'https://app.test/hook', 'authorization' => null, 'authorization_header' => null, 'event' => 'nfse', 'cnpj' => '12345678000123'], 200)]);

        $hook = focusClient()->registerHook('12345678000123', NfseStandard::Municipal, 'https://app.test/api/fiscal/focusnfe/webhook/abc', 'segredo-do-hook', 'X-FocusNfe-Secret');

        expect($hook['id'])->toBe('Vj5rmkBq');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === FOCUS_HOMOLOG . '/v2/hooks'
            && $request->data() === [
                'cnpj'                 => '12345678000123',
                'event'                => 'nfse',
                'url'                  => 'https://app.test/api/fiscal/focusnfe/webhook/abc',
                'authorization'        => 'segredo-do-hook',
                'authorization_header' => 'X-FocusNfe-Secret',
            ]);
    });

    it('registra o evento nfsen para o padrão nacional', function () {
        Http::fake(['*' => Http::response(['id' => 'H2', 'event' => 'nfsen'], 200)]);

        focusClient()->registerHook('12345678000123', NfseStandard::National, 'https://app.test/h', 's', 'X-FocusNfe-Secret');

        Http::assertSent(fn (Request $request) => $request['event'] === 'nfsen');
    });

    it('lista os gatilhos (a chamada barata do "testar credencial")', function () {
        Http::fake(['*' => Http::response([['id' => 'Vj5rmkBq', 'event' => 'nfse'], ['id' => 'k2', 'event' => 'nfe']], 200)]);

        expect(focusClient()->listHooks())->toHaveCount(2);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === FOCUS_HOMOLOG . '/v2/hooks');
    });

    it('lista vazia quando a conta não tem gatilho', function () {
        Http::fake(['*' => Http::response([], 200)]);

        expect(focusClient()->listHooks())->toBe([]);
    });

    it('remove o gatilho, e 404 conta como já removido', function () {
        Http::fake([
            '*/v2/hooks/Vj5rmkBq' => Http::response(['id' => 'Vj5rmkBq', 'deleted' => true], 200),
            '*/v2/hooks/ghost'    => Http::response(['codigo' => 'nao_encontrado', 'mensagem' => 'Gatilho não encontrado'], 404),
        ]);

        focusClient()->deleteHook('Vj5rmkBq');
        focusClient()->deleteHook('ghost');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === FOCUS_HOMOLOG . '/v2/hooks/Vj5rmkBq');
    });
});

describe('municípios', function () {
    it('consulta o município por IBGE', function () {
        Http::fake(['*' => Http::response(['codigo_municipio' => '3550308', 'nfse_habilitada' => true, 'possui_cancelamento_nfse' => true], 200)]);

        expect(focusClient()->municipality('3550308')['nfse_habilitada'])->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . '/v2/municipios/3550308');
    });

    it('busca por nome e UF, limitando o tamanho da página', function () {
        Http::fake(['*' => Http::response([['codigo_municipio' => '3550308', 'nome_municipio' => 'São Paulo', 'sigla_uf' => 'SP']], 200)]);

        $rows = focusClient()->searchMunicipalities('sao paulo', 'sp', 500);

        expect($rows)->toHaveCount(1);

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), FOCUS_HOMOLOG . '/v2/municipios?')
                && $query === ['nome' => 'sao paulo', 'sigla_uf' => 'SP', 'limit' => '50'];
        });
    });
});

describe('download de arquivo', function () {
    it('baixa o XML pelo caminho relativo da Focus, com o mesmo token', function () {
        Http::fake(['*' => Http::response('<nfse/>', 200, ['Content-Type' => 'application/xml'])]);

        expect(focusClient()->download('/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse.xml'))->toBe('<nfse/>');

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . '/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse.xml'
            && $request->header('Authorization') === [focusBasic()]);
    });

    it('recusa URL absoluta, caminho fora de /arquivos e ..', function (string $path) {
        Http::fake();

        expect(fn () => focusClient()->download($path))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    })->with([
        'url absoluta'     => 'https://evil.test/arquivos/x.xml',
        'protocolo'        => '//evil.test/arquivos/x.xml',
        'fora de arquivos' => '/v2/hooks',
        'com ..'           => '/arquivos/../v2/empresas',
        'relativo'         => 'arquivos/x.xml',
        'vazio'            => '',
        'com query'        => '/arquivos/x.xml?a=b',
    ]);
});

describe('segredo', function () {
    it('o token nunca aparece em log, exceção nem mensagem ao usuário', function () {
        $logged = [];

        foreach (['info', 'warning'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(function (...$args) use (&$logged) {
                $logged[] = json_encode($args);
            });
        }

        Http::fake([
            '*/v2/nfse?ref=OK'  => Http::response(focusProcessing(), 201),
            '*/v2/nfse?ref=BAD' => Http::response('HTTP Basic: Access denied ' . FOCUS_TOKEN, 401, ['Content-Type' => 'text/html']),
        ]);

        focusClient()->issue(NfseStandard::Municipal, 'OK', ['tomador' => ['razao_social' => 'PII DA CLINICA']]);

        try {
            focusClient()->issue(NfseStandard::Municipal, 'BAD', []);
        } catch (FocusNfeException $e) {
            expect($e->getMessage())->not->toContain(FOCUS_TOKEN)
                ->and($e->userMessage())->not->toContain(FOCUS_TOKEN)
                ->and(json_encode($e->errors))->not->toContain(FOCUS_TOKEN);
        }

        expect($logged)->not->toBeEmpty();

        foreach ($logged as $line) {
            expect($line)->not->toContain(FOCUS_TOKEN)
                ->and($line)->not->toContain('PII DA CLINICA')
                ->and($line)->not->toContain('Basic');
        }
    });

    it('só registra campos da allowlist no log (op, ref, http, status, duração, correlação)', function () {
        $contexts = [];

        Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use (&$contexts) {
            $contexts[] = $context;
        });

        Http::fake(['*' => Http::response(focusProcessing(), 201)]);

        focusClient()->issue(NfseStandard::Municipal, 'OK', ['a' => 'b']);

        expect($contexts)->toHaveCount(1)
            ->and(array_keys($contexts[0]))->toEqualCanonicalizing(['op', 'ref', 'http', 'status', 'duracao_ms', 'correlation_id'])
            ->and($contexts[0])->toMatchArray(['op' => 'issue', 'ref' => 'OK', 'http' => 201, 'status' => 'processando_autorizacao', 'correlation_id' => 'corr-123']);
    });
});
