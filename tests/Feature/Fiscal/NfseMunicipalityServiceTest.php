<?php

use App\DTOs\Fiscal\NfseMunicipality;
use App\Enums\Fiscal\NfseEnvironment;
use App\Exceptions\Fiscal\FocusNfeException;
use App\Services\Fiscal\NfseMunicipalityService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/Support/NfseFixtures.php';

/** Município como a Focus devolve (GET /v2/municipios/{ibge}) — formato do exemplo de Curitiba da documentação. */
function focusMunicipality(array $overrides = []): array
{
    return array_replace([
        'codigo_municipio'                             => '4106902', 'nome_municipio' => 'Curitiba', 'sigla_uf' => 'PR', 'nome_uf' => 'Paraná',
        'nfse_habilitada'                              => true, 'requer_certificado_nfse' => true, 'possui_ambiente_homologacao_nfse' => true,
        'possui_cancelamento_nfse'                     => true, 'provedor_nfse' => 'Tecnos', 'endereco_obrigatorio_nfse' => null,
        'cpf_cnpj_obrigatorio_nfse'                    => null, 'codigo_cnae_obrigatorio_nfse' => true, 'item_lista_servico_obrigatorio_nfse' => false,
        'codigo_tributario_municipio_obrigatorio_nfse' => false, 'status_nfse' => 'ativo', 'data_previsao_reimplementacao_nfse' => null,
        'ultima_emissao_nfse'                          => '2025-10-01T00:00:00Z',
    ], $overrides);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-18 12:00:00');
    nfseCredential(NfseEnvironment::Homologation, ['master_token' => 'tok-master-A']);
    $this->service = app(NfseMunicipalityService::class);
});

afterEach(fn () => Carbon::setTestNow());

it('lê as obrigatoriedades do município com o token master', function () {
    Http::fake(['*' => Http::response(focusMunicipality(), 200)]);

    $municipality = $this->service->obligations('4106902', NfseEnvironment::Homologation);

    expect($municipality)->toBeInstanceOf(NfseMunicipality::class)
        ->and($municipality->name)->toBe('Curitiba')
        ->and($municipality->uf)->toBe('PR')
        ->and($municipality->enabled)->toBeTrue()
        ->and($municipality->allowsCancellation)->toBeTrue()
        ->and($municipality->cnaeRequired)->toBeTrue()
        ->and($municipality->serviceItemRequired)->toBeFalse()
        ->and($municipality->addressRequired)->toBeNull() // indefinido ≠ "não exige"
        ->and($municipality->provider)->toBe('Tecnos')
        ->and($municipality->status)->toBe('ativo')
        ->and($municipality->stale)->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . '/v2/municipios/4106902'
        && $request->header('Authorization') === ['Basic ' . base64_encode('tok-master-A:')]);
});

it('expõe as flags com os nomes da Focus para a tela', function () {
    Http::fake(['*' => Http::response(focusMunicipality(), 200)]);

    $array = $this->service->obligations('4106902', NfseEnvironment::Homologation)->toArray();

    expect($array)->toMatchArray([
        'codigo_municipio'                             => '4106902',
        'nfse_habilitada'                              => true,
        'possui_cancelamento_nfse'                     => true,
        'endereco_obrigatorio_nfse'                    => null,
        'codigo_cnae_obrigatorio_nfse'                 => true,
        'codigo_tributario_municipio_obrigatorio_nfse' => false,
        'stale'                                        => false,
    ])
        ->and($array)->toHaveKeys([
            'requer_certificado_nfse', 'possui_ambiente_homologacao_nfse', 'cpf_cnpj_obrigatorio_nfse',
            'item_lista_servico_obrigatorio_nfse', 'provedor_nfse', 'status_nfse', 'fetched_at', 'nome_municipio', 'sigla_uf',
        ]);
});

it('tolera município sem os campos novos e ignora os desconhecidos', function () {
    Http::fake(['*' => Http::response(['codigo_municipio' => '4204806', 'nome_municipio' => 'Curitibanos', 'sigla_uf' => 'SC', 'nfse_habilitada' => false, 'status_nfse' => 'nao_implementado', 'campo_novo' => 'x'], 200)]);

    $municipality = $this->service->obligations('4204806', NfseEnvironment::Homologation);

    expect($municipality->enabled)->toBeFalse()
        ->and($municipality->allowsCancellation)->toBeNull()
        ->and($municipality->addressRequired)->toBeNull()
        ->and($municipality->status)->toBe('nao_implementado');
});

it('guarda em cache: a segunda leitura não chama a Focus', function () {
    Http::fake(['*' => Http::response(focusMunicipality(), 200)]);

    $this->service->obligations('4106902', NfseEnvironment::Homologation);
    $second = $this->service->obligations('4106902', NfseEnvironment::Homologation);

    Http::assertSentCount(1);

    expect($second->name)->toBe('Curitiba')->and($second->stale)->toBeFalse();
});

it('guarda por ambiente e por município', function () {
    nfseCredential(NfseEnvironment::Production, ['master_token' => 'tok-master-B']);

    Http::fake(['*' => Http::response(focusMunicipality(), 200)]);

    $this->service->obligations('4106902', NfseEnvironment::Homologation);
    $this->service->obligations('4106902', NfseEnvironment::Production);
    $this->service->obligations('3550308', NfseEnvironment::Homologation);

    Http::assertSentCount(3);
});

it('força nova busca com refresh e guarda a resposta nova', function () {
    Http::fake(['*' => Http::sequence()->push(focusMunicipality(['possui_cancelamento_nfse' => true]), 200)->push(focusMunicipality(['possui_cancelamento_nfse' => false]), 200)]);

    $this->service->obligations('4106902', NfseEnvironment::Homologation);
    $refreshed = $this->service->obligations('4106902', NfseEnvironment::Homologation, refresh: true);

    expect($refreshed->allowsCancellation)->toBeFalse()
        ->and($this->service->cached('4106902', NfseEnvironment::Homologation)->allowsCancellation)->toBeFalse();
});

it('busca de novo quando a cópia passou de 24 horas', function () {
    Http::fake(['*' => Http::response(focusMunicipality(), 200)]);

    $this->service->obligations('4106902', NfseEnvironment::Homologation);

    Carbon::setTestNow('2026-10-19 11:59:00'); // 23h59

    $this->service->obligations('4106902', NfseEnvironment::Homologation);
    Http::assertSentCount(1);

    Carbon::setTestNow('2026-10-19 12:01:00'); // 24h01

    $this->service->obligations('4106902', NfseEnvironment::Homologation);
    Http::assertSentCount(2);
});

it('com a Focus fora do ar devolve a última cópia marcada como antiga, em vez de travar', function () {
    Http::fake(['*' => Http::sequence()->push(focusMunicipality(), 200)->push('Service Unavailable', 503)]);

    $this->service->obligations('4106902', NfseEnvironment::Homologation);

    Carbon::setTestNow('2026-10-20 12:00:00');

    $stale = $this->service->obligations('4106902', NfseEnvironment::Homologation);

    expect($stale->stale)->toBeTrue()
        ->and($stale->name)->toBe('Curitiba')
        ->and($stale->fetchedAt)->toStartWith('2026-10-18');
});

it('sem cópia e com a Focus fora do ar, o erro aparece', function () {
    Http::fake(['*' => Http::response('Service Unavailable', 503)]);

    expect(fn () => $this->service->obligations('4106902', NfseEnvironment::Homologation))->toThrow(FocusNfeException::class);
});

it('token recusado aparece mesmo havendo cópia (não é falha de rede)', function () {
    Http::fake(['*' => Http::sequence()->push(focusMunicipality(), 200)->push('HTTP Basic: Access denied', 401)]);

    $this->service->obligations('4106902', NfseEnvironment::Homologation);

    try {
        $this->service->obligations('4106902', NfseEnvironment::Homologation, refresh: true);
        $this->fail('esperava FocusNfeException');
    } catch (FocusNfeException $e) {
        expect($e->errorCode)->toBe(FocusNfeException::AUTH);
    }
});

it('sem token master não chama a Focus', function () {
    nfseCredential(NfseEnvironment::Production, ['master_token' => null]);

    Http::fake();

    expect(fn () => $this->service->obligations('4106902', NfseEnvironment::Production))->toThrow(FocusNfeException::class);

    Http::assertNothingSent();
});

it('aceita o IBGE mascarado e recusa o que não tem 7 dígitos', function () {
    Http::fake(['*' => Http::response(focusMunicipality(), 200)]);

    $this->service->obligations('4106-902', NfseEnvironment::Homologation);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/municipios/4106902'));

    expect(fn () => $this->service->obligations('410690', NfseEnvironment::Homologation))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service->obligations('', NfseEnvironment::Homologation))->toThrow(InvalidArgumentException::class);
});

it('cached() não chama a Focus e devolve null para município nunca consultado', function () {
    Http::fake();

    expect($this->service->cached('4106902', NfseEnvironment::Homologation))->toBeNull();

    Http::assertNothingSent();
});

it('confirma que o IBGE existe e diz que não existe quando a Focus responde 404', function () {
    Http::fake([
        '*/v2/municipios/4106902' => Http::response(focusMunicipality(), 200),
        '*/v2/municipios/9999999' => Http::response(['codigo' => 'nao_encontrado', 'mensagem' => 'Município não encontrado'], 404),
    ]);

    expect($this->service->exists('4106902', NfseEnvironment::Homologation))->toBeTrue()
        ->and($this->service->exists('9999999', NfseEnvironment::Homologation))->toBeFalse();
});

it('exists() deixa passar o erro de token (não responde "não existe" por engano)', function () {
    Http::fake(['*' => Http::response('HTTP Basic: Access denied', 401)]);

    expect(fn () => $this->service->exists('4106902', NfseEnvironment::Homologation))->toThrow(FocusNfeException::class);
});

it('busca municípios por nome e UF e descarta linha sem IBGE válido', function () {
    Http::fake(['*' => Http::response([
        ['codigo_municipio' => '4106902', 'nome_municipio' => 'Curitiba', 'sigla_uf' => 'PR', 'nfse_habilitada' => true, 'status_nfse' => 'ativo'],
        ['codigo_municipio' => '4204806', 'nome_municipio' => 'Curitibanos', 'sigla_uf' => 'SC', 'nfse_habilitada' => false],
        ['codigo_municipio' => 'lixo', 'nome_municipio' => 'Quebrado'],
    ], 200)]);

    expect($this->service->search(NfseEnvironment::Homologation, 'curitiba', null))->toBe([
        ['codigo_municipio' => '4106902', 'nome_municipio' => 'Curitiba', 'sigla_uf' => 'PR', 'nfse_habilitada' => true],
        ['codigo_municipio' => '4204806', 'nome_municipio' => 'Curitibanos', 'sigla_uf' => 'SC', 'nfse_habilitada' => false],
    ]);
});

it('a busca exige token master', function () {
    nfseCredential(NfseEnvironment::Production, ['master_token' => null]);

    Http::fake();

    expect(fn () => $this->service->search(NfseEnvironment::Production, 'sao', 'SP'))->toThrow(FocusNfeException::class);

    Http::assertNothingSent();
});
