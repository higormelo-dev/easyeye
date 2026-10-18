<?php

declare(strict_types=1);

/**
 * Prova REAL do contrato com a Focus NFe — SÓ na HOMOLOGAÇÃO. Sem Http::fake: o FocusNfeClient de verdade fala com
 * https://homologacao.focusnfe.com.br e cada passo confere os CAMPOS que o nosso código lê. O banco do app só é LIDO (a
 * credencial de homologação e a configuração do prestador); nada é gravado nele (sem NfseDocument, sem RefreshDatabase,
 * sem contador de DPS) — a prova é do contrato da API, não da UI nem do banco.
 *
 * Fluxo (uma `ref` única, `SMOKE…`):
 *   1. monta o corpo com o builder REAL do padrão configurado (municipal ou nacional) e um tomador sintético (CNPJ válido
 *      gerado, endereço de exemplo) — nenhum dado de clínica real;
 *   2. POST /v2/{nfse|nfsen}?ref= → 201/202 `processando_autorizacao`;
 *   3. consulta até `autorizado` (até FOCUSNFE_SMOKE_TIMEOUT segundos, padrão 120) conferindo numero / codigo_verificacao /
 *      caminho_xml_nota_fiscal / url_danfse; recusa da prefeitura sai com os `erros` da Focus no aviso do teste;
 *   4. baixa o XML pelo caminho relativo (Basic com o mesmo token) e confere que é XML;
 *   5. cancela (DELETE) e confere `cancelado` + caminho_xml_cancelamento — sempre, mesmo se um passo anterior falhar
 *      (nota de homologação não vale nada, mas fica limpa);
 *   6. e-mail de cópia SÓ se FOCUSNFE_SMOKE_EMAIL estiver definido.
 *
 * TRAVA DUPLA (nunca contra produção) — sem as duas o teste é PULADO, sem nenhuma chamada de rede:
 *   a) FOCUSNFE_HOMOLOG_SMOKE=1 no ambiente do comando;
 *   b) existir credencial `homologacao` com o token da empresa no banco lido E o host do ambiente de homologação ser
 *      exatamente `homologacao.focusnfe.com.br` (https). O teste só usa a linha `homologacao` — a de produção nunca é lida —
 *      e uma 3ª trava (middleware global do HTTP) derruba qualquer requisição para outro host/esquema.
 *
 * Como rodar (o Pest usa o banco de TESTE; a credencial está no banco da aplicação — aponte-o só para esta execução, o
 * teste não usa RefreshDatabase):
 *
 *   DB_DATABASE=<banco do app> FOCUSNFE_HOMOLOG_SMOKE=1 ./vendor/bin/pest tests/Integration/FocusNfeHomologSmokeTest.php
 *
 * Pré-requisitos: prestador configurado em Manager → Nota fiscal (CNPJ, município, IM/item ou código nacional), empresa
 * cadastrada na Focus com certificado e o padrão habilitado em homologação. O token nunca aparece na saída.
 * Opcionais: FOCUSNFE_SMOKE_TIMEOUT (segundos de espera da autorização), FOCUSNFE_SMOKE_EMAIL.
 *
 * tests/Integration só roda por caminho explícito ou na suíte completa; sem a trava, este arquivo apenas pula.
 */

use App\DTOs\Fiscal\NfsePayloadContext;
use App\Enums\Fiscal\NfseEnvironment;
use App\Exceptions\Fiscal\FocusNfeException;
use App\Models\Fiscal\NfseCredential;
use App\Rules\CpfCnpj;
use App\Services\Fiscal\FocusNfeClient;
use App\Services\Fiscal\Payloads\NfsePayloadFactory;
use App\Support\Fiscal\NfseSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

const FOCUS_SMOKE_HOST = 'homologacao.focusnfe.com.br';

/** O endereço é da homologação da Focus? (https + host exato, sem porta nem credencial na URL.) */
function focusSmokeIsHomologUrl(string $url): bool
{
    $parts = parse_url($url);

    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && ($parts['host'] ?? '') === FOCUS_SMOKE_HOST
        && ! isset($parts['port']) && ! isset($parts['user']) && ! isset($parts['pass']);
}

/** CNPJ válido (dígitos verificadores corretos) e não repetido, para o tomador sintético. */
function focusSmokeCnpj(): string
{
    do {
        $digits = array_map(static fn () => random_int(0, 9), range(1, 12));
    } while (count(array_unique($digits)) === 1);

    foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += $digits[$i] * $weight;
        }

        $rest     = $sum % 11;
        $digits[] = $rest < 2 ? 0 : 11 - $rest;
    }

    return implode('', $digits);
}

/** Motivo para PULAR (null = pode rodar). Nenhuma chamada de rede aqui. */
function focusSmokeSkipReason(): ?string
{
    if (getenv('FOCUSNFE_HOMOLOG_SMOKE') !== '1') {
        return 'FOCUSNFE_HOMOLOG_SMOKE=1 não definido (smoke da Focus NFe é opt-in).';
    }

    if (! focusSmokeIsHomologUrl(NfseEnvironment::Homologation->baseUrl())) {
        return 'O host configurado para a homologação da Focus não é https://' . FOCUS_SMOKE_HOST . ' — recusado.';
    }

    try {
        $credential = NfseCredential::query()->where('environment', NfseEnvironment::Homologation->value)->first();
    } catch (Throwable) {
        return 'Banco sem a tabela nfse_credentials (aponte DB_DATABASE para o banco do app).';
    }

    if ($credential === null || ! $credential->hasToken()) {
        return 'Sem credencial de HOMOLOGAÇÃO com token da empresa no banco lido (Manager → Nota fiscal).';
    }

    $settings = new NfseSettings();

    if (! $settings->isConfigured()) {
        return 'Prestador incompleto: faltam ' . implode(', ', $settings->missing()) . ' (Manager → Nota fiscal).';
    }

    return null;
}

it('só aceita o host https da homologação da Focus (nunca produção, porta ou credencial na URL)', function () {
    expect(focusSmokeIsHomologUrl('https://homologacao.focusnfe.com.br'))->toBeTrue()
        ->and(focusSmokeIsHomologUrl('https://homologacao.focusnfe.com.br/v2/nfse?ref=X'))->toBeTrue()
        ->and(focusSmokeIsHomologUrl('https://api.focusnfe.com.br'))->toBeFalse()
        ->and(focusSmokeIsHomologUrl('http://homologacao.focusnfe.com.br'))->toBeFalse()
        ->and(focusSmokeIsHomologUrl('https://homologacao.focusnfe.com.br:8443'))->toBeFalse()
        ->and(focusSmokeIsHomologUrl('https://x@homologacao.focusnfe.com.br'))->toBeFalse()
        ->and(focusSmokeIsHomologUrl('https://homologacao.focusnfe.com.br.evil.test'))->toBeFalse()
        ->and(focusSmokeIsHomologUrl('https://evil.test/homologacao.focusnfe.com.br'))->toBeFalse()
        ->and(NfseEnvironment::Homologation->baseUrl())->toStartWith('https://' . FOCUS_SMOKE_HOST)
        ->and(NfseEnvironment::Production->baseUrl())->not->toContain('homologacao');
});

it('gera um CNPJ sintético válido para o tomador', function () {
    expect(CpfCnpj::isValid(focusSmokeCnpj()))->toBeTrue();
});

it('prova o contrato REAL da Focus NFe na homologação: emitir → autorizar → XML → cancelar', function () {
    if (($reason = focusSmokeSkipReason()) !== null) {
        $this->markTestSkipped($reason);
    }

    // 3ª trava: nada sai para fora da homologação, nem por redirecionamento.
    Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
        if (! focusSmokeIsHomologUrl((string) $request->getUri())) {
            throw new RuntimeException('Smoke da Focus recusou requisição fora da homologação: ' . $request->getUri()->getHost());
        }

        return $request;
    });

    $credential = NfseCredential::query()->where('environment', NfseEnvironment::Homologation->value)->firstOrFail();
    $client     = FocusNfeClient::make(NfseEnvironment::Homologation, (string) $credential->token, 'smoke-' . bin2hex(random_bytes(4)));
    $settings   = new NfseSettings();
    $standard   = $settings->standard();
    $prestador  = $settings->prestador();
    $ref        = 'SMOKE' . strtoupper(bin2hex(random_bytes(12)));
    $step       = 'montar o corpo';
    $accepted   = false;

    $address = [
        'logradouro' => 'AVENIDA PAULISTA', 'numero' => '1000', 'complemento' => 'SALA 1', 'bairro' => 'BELA VISTA',
        'uf'         => 'SP', 'cep' => '01310100', 'codigo_municipio' => '3550308',
    ];

    try {
        $payload = (new NfsePayloadFactory())->for($standard)->build(new NfsePayloadContext(
            issuedAt: CarbonImmutable::now()->subMinute(),
            competence: CarbonImmutable::today(),
            amount: 10.00,
            description: 'Teste de integração EasyEye (homologação) — sem valor fiscal',
            prestador: $prestador,
            payer: ['name' => 'EMPRESA SINTETICA DE TESTE LTDA', 'document' => focusSmokeCnpj(), 'document_type' => 'cnpj', 'email' => (string) (getenv('FOCUSNFE_SMOKE_EMAIL') ?: 'smoke@example.test'), 'address' => $address],
            // Série da configuração e número alto aleatório: não consome nem grava o contador real.
            dpsSeries: $settings->serieDps(),
            dpsNumber: random_int(900_000_000, 999_999_999),
            descriptionLimit: (int) config('fiscal.limits.discriminacao', 2000),
        ));

        // 1. emissão (pré-validação síncrona)
        $step     = 'emitir (POST)';
        $response = $client->issue($standard, $ref, $payload);
        $accepted = true;

        expect($response['status'] ?? null)->toBe('processando_autorizacao');

        // 2. espera da autorização
        $step     = 'aguardar a autorização';
        $deadline = time() + max(10, (int) (getenv('FOCUSNFE_SMOKE_TIMEOUT') ?: 120));
        $note     = [];

        do {
            sleep(3);
            $note = $client->show($standard, $ref) ?? [];
        } while (($note['status'] ?? 'processando_autorizacao') === 'processando_autorizacao' && time() < $deadline);

        if (in_array($note['status'] ?? null, ['erro_autorizacao', 'negado'], true)) {
            $this->fail('A prefeitura/ambiente nacional recusou a nota de teste: ' . json_encode($note['erros'] ?? [], JSON_UNESCAPED_UNICODE));
        }

        expect($note['status'] ?? null)->toBe('autorizado', 'a nota não autorizou no tempo do smoke (FOCUSNFE_SMOKE_TIMEOUT)');
        expect($note)->toHaveKeys(['numero', 'codigo_verificacao', 'caminho_xml_nota_fiscal', 'url_danfse']);
        expect((string) $note['caminho_xml_nota_fiscal'])->toStartWith('/arquivos/');

        // 3. XML pelo caminho relativo, com o mesmo token
        $step = 'baixar o XML';
        $xml  = $client->download((string) $note['caminho_xml_nota_fiscal']);

        expect(str_starts_with(ltrim($xml), '<'))->toBeTrue();

        // 4. e-mail de cópia (opcional)
        if (filled(getenv('FOCUSNFE_SMOKE_EMAIL'))) {
            $step = 'reenviar o e-mail';
            $client->email($standard, $ref, [(string) getenv('FOCUSNFE_SMOKE_EMAIL')]);
        }
    } finally {
        // 5. cancelamento: sempre que a Focus chegou a aceitar a nota, para a homologação ficar limpa.
        if ($accepted) {
            try {
                $client->cancel($standard, $ref, 'Cancelamento de nota de teste da integração EasyEye (homologação)');
            } catch (FocusNfeException) {
                // nota ainda em processamento/recusada não cancela — o aviso do passo que falhou já explica.
            }
        }
    }

    // Conferência do cancelamento (fora do finally, para o erro do passo anterior prevalecer).
    $step      = 'conferir o cancelamento';
    $cancelled = $client->show($standard, $ref) ?? [];

    expect($cancelled['status'] ?? null)->toBe('cancelado', "passo: {$step}");
    expect((string) ($cancelled['caminho_xml_cancelamento'] ?? ''))->toStartWith('/arquivos/');
});
