<?php

declare(strict_types=1);

use App\Services\Billing\GatewayCredentialResolver;
use App\Services\Billing\Gateways\AsaasGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * billing:asaas-webhook — registra/remove/lista o webhook do SANDBOX do Asaas para a URL do túnel (E2E). Http::fake:
 * nada sai de verdade. Só sandbox (trava do billing:gateway-environment), authToken = webhook_secret, idempotente.
 */
const AWC_TOKEN = 'whsec_asaas_sandbox_token_com_mais_de_32_caracteres';

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'billing.gateways.asaas.base_url'       => 'https://api-sandbox.asaas.com',
        'billing.gateways.asaas.secret'         => '$aact_hmlg_000000_chave_de_teste',
        'billing.gateways.asaas.webhook_secret' => AWC_TOKEN,
    ]);
});

const AWC_NGROK = 'http://127.0.0.1:4040/api/tunnels';

/** Túneis que a API (falsa) do ngrok lista por padrão: todos apontam para o app local. */
const AWC_TUNNEL_HOSTS = ['abc.ngrok-free.app', 'abc123.ngrok-free.app', 'abc.ngrok.app', 'abc.ngrok.io', 'abc.ngrok-free.dev', 'a.b.ngrok.io', 'abc.tunel.exemplo.dev'];

function awcTunnels(array $publicUrls, mixed $addr = 'http://127.0.0.1:8086'): array
{
    return ['tunnels' => array_map(fn (string $url) => ['name' => 'command_line', 'public_url' => $url, 'proto' => 'https', 'config' => ['addr' => $addr]], $publicUrls)];
}

function awcFake(array $existing = [], ?array $post = null, array|string|null $tunnels = null): void
{
    Http::fake([
        AWC_NGROK                                     => Http::response($tunnels ?? awcTunnels(array_map(fn (string $h) => "https://{$h}", AWC_TUNNEL_HOSTS))),
        'https://api-sandbox.asaas.com/v3/webhooks?*' => Http::response(['data' => $existing]),
        'https://api-sandbox.asaas.com/v3/webhooks/*' => fn (Request $r) => $r->method() === 'DELETE' ? Http::response(['deleted' => true, 'id' => 'wh_x']) : Http::response(['id' => 'wh_1', 'authToken' => 'NAO-DEVE-SAIR', 'url' => (string) $r['url']]),
        'https://api-sandbox.asaas.com/v3/webhooks'   => Http::response($post ?? ['id' => 'wh_1', 'url' => 'x', 'hasAuthToken' => true]),
    ]);
}

/** Nada foi para o Asaas e a ÚNICA coisa enviada (se algo) foi a consulta à API local do ngrok. */
function awcAssertOnlyNgrokQueried(int $queries = 1): void
{
    Http::assertNotSent(fn (Request $r) => ! str_starts_with($r->url(), 'http://127.0.0.1:4'));
    Http::assertSentCount($queries);
}

function awcWebhook(string $id, string $url, array $over = []): array
{
    return array_merge(['id' => $id, 'name' => 'EasyEye', 'url' => $url, 'enabled' => true, 'interrupted' => false, 'events' => ['PAYMENT_CONFIRMED'], 'hasAuthToken' => true], $over);
}

const AWC_PATH = '/api/billing/webhooks/asaas';

it('PRODUÇÃO ou credencial desconhecida: aborta (exit 1) sem chamar a API', function (string $baseUrl, ?string $secret) {
    config(['billing.gateways.asaas.base_url' => $baseUrl, 'billing.gateways.asaas.secret' => $secret]);
    Http::fake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('só roda no sandbox')
        ->assertExitCode(1);
    $this->artisan('billing:asaas-webhook', ['action' => 'list'])->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    'host de produção'       => ['https://api.asaas.com', '$aact_prod_000'],
    'chave de produção'      => ['https://api-sandbox.asaas.com', '$aact_prod_000'],
    'chave hmlg em produção' => ['https://api.asaas.com', '$aact_hmlg_000'],
    'sem credencial'         => ['https://api-sandbox.asaas.com', null],
]);

it('register cria o webhook com a url do túnel + rota do app, o token do webhook_secret e os eventos que o app trata', function () {
    awcFake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc123.ngrok-free.app/'])
        ->expectsOutputToContain('Webhook criado: wh_1')
        ->doesntExpectOutputToContain(AWC_TOKEN)
        ->assertExitCode(0);

    Http::assertSent(function (Request $r) {
        if ($r->method() !== 'POST' || $r->url() !== 'https://api-sandbox.asaas.com/v3/webhooks') {
            return false;
        }

        $events = $r['events'];

        return $r['url'] === 'https://abc123.ngrok-free.app' . AWC_PATH
            && $r['authToken'] === AWC_TOKEN
            && $r['enabled'] === true
            && $r['interrupted'] === false
            && $r['apiVersion'] === 3
            && $r['sendType'] === 'SEQUENTIALLY'
            && filled($r['name']) && filled($r['email'])
            && $events === app(AsaasGateway::class)->handledWebhookEvents()
            && in_array('PAYMENT_CONFIRMED', $events, true) && in_array('CHECKOUT_PAID', $events, true) && in_array('SUBSCRIPTION_CREATED', $events, true)
            && collect($events)->every(fn (string $e) => preg_match('/^(PAYMENT|SUBSCRIPTION|CHECKOUT)_/', $e) === 1);
    });
});

it('o authToken é o webhook_secret do MANAGER (vence o .env)', function () {
    app()->instance(GatewayCredentialResolver::class, new class() extends GatewayCredentialResolver {
        public function resolveWebhookSecret(string $gatewayCode): ?string
        {
            return 'manager_webhook_secret_com_mais_de_32_caracteres';
        }
    });
    awcFake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['authToken'] === 'manager_webhook_secret_com_mais_de_32_caracteres');
});

it('register é IDEMPOTENTE: com a url já cadastrada atualiza (PUT) o mesmo webhook, reativa o interrompido e não cria outro', function () {
    awcFake([awcWebhook('wh_old', 'https://abc.ngrok-free.app' . AWC_PATH, ['interrupted' => true, 'enabled' => false]), awcWebhook('wh_other', 'https://outro.ngrok-free.app' . AWC_PATH)]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://ABC.ngrok-free.app'])
        ->expectsOutputToContain('Webhook atualizado: wh_1')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://api-sandbox.asaas.com/v3/webhooks/wh_old' && $r['interrupted'] === false && $r['enabled'] === true);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'wh_other'));
});

it('register aborta sem webhook_secret (vazio ou < 32 caracteres): exit 1, nada é enviado', function (string $secret) {
    config(['billing.gateways.asaas.webhook_secret' => $secret]);
    awcFake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(1);

    Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['POST', 'PUT'], true));
})->with([
    'secret vazio' => [''],
    'secret curto' => ['curto'],
]);

it('--url só aceita https://<rótulo>.<domínio de túnel da allowlist> — qualquer outra coisa: exit 2 e NADA vai à API (o token não sai para host de terceiro)', function (string $url) {
    Http::fake();

    foreach (['register', 'remove'] as $action) {
        $this->artisan('billing:asaas-webhook', ['action' => $action, '--url' => $url])
            ->expectsOutputToContain('domínios aceitos')
            ->doesntExpectOutputToContain(AWC_TOKEN)
            ->assertExitCode(2);
    }

    Http::assertNothingSent();
})->with([
    'host de terceiro'                   => ['https://evil.com'],
    'sufixo no meio (host do atacante)'  => ['https://x.ngrok-free.app.evil.com'],
    'sem o ponto do sufixo'              => ['https://evilngrok-free.app'],
    'só o apex do domínio'               => ['https://ngrok-free.app'],
    'IPv4'                               => ['https://1.2.3.4'],
    'IPv4 loopback'                      => ['https://127.0.0.1'],
    'IPv6 loopback entre colchetes'      => ['https://[::1]'],
    'IPv4 mapeado em IPv6'               => ['https://[::ffff:127.0.0.1]'],
    'rede privada 10/8'                  => ['https://10.0.0.5'],
    'rede privada 192.168/16'            => ['https://192.168.0.10'],
    'link-local (metadata de nuvem)'     => ['https://169.254.169.254'],
    'localhost'                          => ['https://localhost'],
    'localhost.localdomain'              => ['https://localhost.localdomain'],
    'porta explícita'                    => ['https://x.ngrok-free.app:8443'],
    'porta 443 explícita'                => ['https://x.ngrok-free.app:443'],
    'http'                               => ['http://x.ngrok-free.app'],
    'sem esquema'                        => ['x.ngrok-free.app'],
    'caminho'                            => ['https://x.ngrok-free.app/api'],
    'query'                              => ['https://x.ngrok-free.app?x=1'],
    'fragmento'                          => ['https://x.ngrok-free.app#f'],
    'userinfo'                           => ['https://user@x.ngrok-free.app'],
    'userinfo com senha'                 => ['https://user:pw@x.ngrok-free.app'],
    'userinfo disfarçando o host'        => ['https://x.ngrok-free.app@evil.com'],
    'barra invertida (parsers divergem)' => ['https://evil.com\\@x.ngrok-free.app'],
    'FQDN com ponto final'               => ['https://x.ngrok-free.app.'],
    'espaço no host'                     => ['https://x .ngrok-free.app'],
    'sufixo parecido (.ngrok.com)'       => ['https://x.ngrok.com'],
    'vazio'                              => [''],
]);

it('--url aceita os domínios de túnel da allowlist (subdomínios em qualquer profundidade, caixa ignorada)', function (string $url, string $expected) {
    awcFake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => $url])->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['url'] === $expected . AWC_PATH);
})->with([
    'ngrok-free.app'      => ['https://abc.ngrok-free.app', 'https://abc.ngrok-free.app'],
    'ngrok.app'           => ['https://abc.ngrok.app', 'https://abc.ngrok.app'],
    'ngrok.io'            => ['https://abc.ngrok.io', 'https://abc.ngrok.io'],
    'ngrok-free.dev'      => ['https://abc.ngrok-free.dev', 'https://abc.ngrok-free.dev'],
    'vários rótulos'      => ['https://a.b.ngrok.io', 'https://a.b.ngrok.io'],
    'caixa e barra final' => ['https://ABC.Ngrok-Free.App/', 'https://abc.ngrok-free.app'],
]);

it('allowlist vazia ou ausente: nenhum host passa (falha fechada)', function (mixed $hosts) {
    config(['billing.sandbox_webhook_hosts' => $hosts]);
    Http::fake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(2);

    Http::assertNothingSent();
})->with(['lista vazia' => [[]], 'nula' => [null], 'só pontos/brancos' => [['.', ' ']]]);

it('IP literal nunca passa, nem com a allowlist mal configurada (sufixo numérico)', function (string $url) {
    config(['billing.sandbox_webhook_hosts' => ['.0.1', '.5', '.4', '.254', '.1.1']]);
    Http::fake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => $url])->assertExitCode(2);

    Http::assertNothingSent();
})->with([
    'loopback'    => ['https://127.0.0.1'],
    '10/8'        => ['https://10.0.0.5'],
    'público'     => ['https://1.2.3.4'],
    'metadata'    => ['https://169.254.169.254'],
    'DNS 1.1.1.1' => ['https://1.1.1.1'],
]);

it('register PROVA o túnel local: aceita o --url que é public_url exato de um túnel da API do ngrok com destino em loopback', function (string $addr) {
    awcFake(tunnels: awcTunnels(['https://abc.ngrok-free.app'], $addr));

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === AWC_NGROK);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['url'] === 'https://abc.ngrok-free.app' . AWC_PATH);
})->with([
    'http://127.0.0.1:8086', '127.0.0.1:8086', 'HTTP://127.0.0.1:8086', 'https://127.0.0.1:8443', 'http://[::1]:8086', '[::1]:8086',
]);

it('túnel de OUTRA pessoa (ngrok-free.app é domínio multi-tenant) passa pela allowlist mas NÃO pela prova: exit 2 e só a consulta local sai', function () {
    awcFake(tunnels: awcTunnels(['https://abc.ngrok-free.app']));

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://atacante.ngrok-free.app'])
        ->expectsOutputToContain('não é um túnel desta máquina')
        ->doesntExpectOutputToContain(AWC_TOKEN)
        ->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
});

it('a url pública do túnel tem de ser IGUAL à do --url: prefixo, subdomínio, esquema, porta e caminho não valem', function (string $publicUrl) {
    awcFake(tunnels: awcTunnels([$publicUrl]));

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
})->with([
    'host do atacante com o nosso de prefixo' => ['https://abc.ngrok-free.app.evil.com'],
    'subdomínio a mais'                       => ['https://x.abc.ngrok-free.app'],
    'http'                                    => ['http://abc.ngrok-free.app'],
    'porta'                                   => ['https://abc.ngrok-free.app:8443'],
    'caminho'                                 => ['https://abc.ngrok-free.app/x'],
    'outro rótulo'                            => ['https://abd.ngrok-free.app'],
]);

it('destino REMOTO do túnel (config.addr fora de loopback) não prova nada', function (mixed $addr) {
    awcFake(tunnels: awcTunnels(['https://abc.ngrok-free.app'], $addr));

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
})->with([
    'localhost (nome, não IP)'  => ['http://localhost:8086'],
    'localhost sem esquema'     => ['localhost:8086'],
    'localhost https'           => ['https://localhost:8443'],
    'localhost em maiúsculas'   => ['HTTP://LOCALHOST:8086'],
    'só a porta (= localhost)'  => ['8086'],
    'IPv6 loopback por extenso' => ['http://[0:0:0:0:0:0:0:1]:8086'],
    'rede privada'              => ['http://192.168.0.10:8086'],
    'rede privada sem porta'    => ['http://10.0.0.5'],
    'host de terceiro'          => ['evil.com:8086'],
    'host de terceiro com http' => ['http://evil.com'],
    'prefixo localhost'         => ['http://localhost.evil.com:8086'],
    'userinfo disfarça o host'  => ['http://localhost@evil.com:8086'],
    'userinfo com loopback'     => ['http://user:pw@localhost:8086'],
    'esquema que não é http'    => ['tcp://localhost:8086'],
    'loopback fora da lista'    => ['0.0.0.0:8086'],
    'vazio'                     => [''],
    'nulo'                      => [null],
    'não é texto'               => [[]],
]);

it('entradas malformadas na lista de túneis são ignoradas (nunca provam)', function () {
    awcFake(tunnels: ['tunnels' => [null, 'texto', 7, ['public_url' => 123, 'config' => ['addr' => '127.0.0.1:8086']], ['public_url' => 'https://abc.ngrok-free.app', 'config' => '127.0.0.1:8086'], ['public_url' => 'https://abc.ngrok-free.app']]]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
});

it('"tunnels" tem de ser uma LISTA JSON de objetos: objeto/mapa (mesmo com uma entrada válida) e itens em formato de lista não provam nada', function (array $body) {
    awcFake(tunnels: $body);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('não devolveu a lista de túneis')
        ->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
})->with(function () {
    $valid = ['public_url' => 'https://abc.ngrok-free.app', 'config' => ['addr' => '127.0.0.1:8086']];

    return [
        'mapa nome => túnel'            => [['tunnels' => ['command_line' => $valid]]],
        'mapa com chave numérica solta' => [['tunnels' => [1 => $valid]]],
        'mapa com a entrada válida'     => [['tunnels' => ['x' => $valid, 'y' => ['public_url' => 'https://outro.ngrok-free.app']]]],
    ];
});

it('o TIPO JSON de "tunnels" é conferido no corpo bruto: só ARRAY JSON é lista — objeto com chaves "0","1"… (que o decode associativo achata em lista) não prova nada', function (string $json) {
    awcFake(tunnels: $json);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('não devolveu a lista de túneis')
        ->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
})->with(function () {
    $valid = '{"public_url":"https://abc.ngrok-free.app","config":{"addr":"http://127.0.0.1:8086"}}';

    return [
        'objeto {"0": túnel}'             => ['{"tunnels":{"0":' . $valid . '}}'],
        'objeto {"0": túnel, "1": túnel}' => ['{"tunnels":{"0":' . $valid . ',"1":' . $valid . '}}'],
        'objeto {"0": túnel} com espaços' => ["{ \"tunnels\" : { \"0\" : {$valid} } }"],
        'objeto com chave numérica solta' => ['{"tunnels":{"7":' . $valid . '}}'],
        'objeto vazio'                    => ['{"tunnels":{}}'],
        'tunnels nulo'                    => ['{"tunnels":null}'],
        'tunnels texto'                   => ['{"tunnels":"abc"}'],
        'tunnels número'                  => ['{"tunnels":1}'],
        'array no topo (sem "tunnels")'   => ['[' . $valid . ']'],
        'texto no topo'                   => ['"tunnels"'],
        'null no topo'                    => ['null'],
    ];
});

it('o mesmo corpo bruto com "tunnels" ARRAY JSON prova (controle): barras escapadas, espaços e outras chaves não atrapalham', function (string $json) {
    awcFake(tunnels: $json);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(0);
})->with([
    'array simples'           => ['{"tunnels":[{"public_url":"https://abc.ngrok-free.app","config":{"addr":"http://127.0.0.1:8086"}}]}'],
    'barras escapadas'        => ['{"tunnels":[{"public_url":"https:\/\/abc.ngrok-free.app","config":{"addr":"http:\/\/127.0.0.1:8086"}}]}'],
    'com uri e outras chaves' => ['{"tunnels":[{"name":"command_line","public_url":"https://abc.ngrok-free.app","proto":"https","config":{"addr":"http://127.0.0.1:8086","inspect":true}}],"uri":"/api/tunnels"}'],
]);

it('no corpo bruto, item ou config que não são OBJETO JSON, ou addr que não é texto, não provam', function (string $json) {
    awcFake(tunnels: $json);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
})->with([
    'config é array JSON'     => ['{"tunnels":[{"public_url":"https://abc.ngrok-free.app","config":[]}]}'],
    'config é array com addr' => ['{"tunnels":[{"public_url":"https://abc.ngrok-free.app","config":["http://127.0.0.1:8086"]}]}'],
    'config é texto'          => ['{"tunnels":[{"public_url":"https://abc.ngrok-free.app","config":"http://127.0.0.1:8086"}]}'],
    'addr é número'           => ['{"tunnels":[{"public_url":"https://abc.ngrok-free.app","config":{"addr":8086}}]}'],
    'addr é array'            => ['{"tunnels":[{"public_url":"https://abc.ngrok-free.app","config":{"addr":["127.0.0.1:8086"]}}]}'],
    'public_url é número'     => ['{"tunnels":[{"public_url":1,"config":{"addr":"127.0.0.1:8086"}}]}'],
    'item é array JSON'       => ['{"tunnels":[["https://abc.ngrok-free.app",{"addr":"127.0.0.1:8086"}]]}'],
    'item é texto'            => ['{"tunnels":["https://abc.ngrok-free.app"]}'],
    'JSON truncado'           => ['{"tunnels":[{"public_url":"https://abc.ngrok-free.app","config":{"addr":"127.0.0.1:8086"}'],
]);

it('item do túnel no formato errado (lista, sem config, config em lista) não prova nada, mesmo com os valores certos', function (array $tunnel) {
    awcFake(tunnels: ['tunnels' => [$tunnel]]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
})->with([
    'item é lista'            => [['https://abc.ngrok-free.app', ['addr' => '127.0.0.1:8086']]],
    'config é lista'          => [['public_url' => 'https://abc.ngrok-free.app', 'config' => ['127.0.0.1:8086']]],
    'addr fora de config'     => [['public_url' => 'https://abc.ngrok-free.app', 'addr' => '127.0.0.1:8086']],
    'config.addr não é texto' => [['public_url' => 'https://abc.ngrok-free.app', 'config' => ['addr' => ['127.0.0.1:8086']]]],
]);

it('lista de túneis válida com a entrada certa NÃO na primeira posição ainda prova', function () {
    awcFake(tunnels: ['tunnels' => [
        ['public_url' => 'https://outro.ngrok-free.app', 'config' => ['addr' => 'http://192.168.0.2:80']],
        ['public_url' => 'https://abc.ngrok-free.app', 'config' => ['addr' => 'http://127.0.0.1:8086']],
    ]]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(0);
});

it('--ngrok-api só aceita a API LOCAL (http + loopback, sem credenciais nem caminho): nada é consultado nem enviado', function (string $api) {
    Http::fake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app', '--ngrok-api' => $api])
        ->expectsOutputToContain('--ngrok-api inválida')
        ->assertExitCode(2);

    Http::assertNothingSent();
})->with([
    'host de terceiro'         => ['http://evil.com:4040'],
    'rede privada'             => ['http://192.168.0.5:4040'],
    'https'                    => ['https://127.0.0.1:4040'],
    'caminho'                  => ['http://127.0.0.1:4040/x'],
    'userinfo'                 => ['http://user@127.0.0.1:4040'],
    'userinfo disfarça o host' => ['http://127.0.0.1:4040@evil.com'],
    'prefixo localhost'        => ['http://localhost.evil.com:4040'],
    'esquema ftp'              => ['ftp://127.0.0.1:4040'],
    'query'                    => ['http://127.0.0.1:4040?x=1'],
    'vazia'                    => [''],
]);

it('--ngrok-api em outra porta de loopback é consultada (a do web_addr do sandbox-run.sh)', function (string $api, string $queried) {
    awcFake();
    Http::fake([$queried => Http::response(awcTunnels(['https://abc.ngrok-free.app']))]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app', '--ngrok-api' => $api])->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === $queried);
})->with([
    'porta própria' => ['http://127.0.0.1:4141', 'http://127.0.0.1:4141/api/tunnels'],
    'barra final'   => ['http://127.0.0.1:4141/', 'http://127.0.0.1:4141/api/tunnels'],
    'IPv6 loopback' => ['http://[::1]:4143', 'http://[::1]:4143/api/tunnels'],
]);

it('a consulta ao ngrok vai DIRETO ao loopback: HTTP_PROXY/HTTPS_PROXY do ambiente não entram (servidor real, proxy morto)', function () {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open indisponível.');
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port   = (int) parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    $router = tempnam(sys_get_temp_dir(), 'awc-ngrok-');
    file_put_contents($router, '<?php header("Content-Type: application/json"); echo json_encode(["tunnels" => [["public_url" => "https://abc.ngrok-free.app", "config" => ["addr" => "http://127.0.0.1:8086"]]]]);');
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);

    $previous = ['HTTP_PROXY' => getenv('HTTP_PROXY'), 'http_proxy' => getenv('http_proxy'), 'HTTPS_PROXY' => getenv('HTTPS_PROXY'), 'https_proxy' => getenv('https_proxy'), 'NO_PROXY' => getenv('NO_PROXY')];

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1); $i++) {
            usleep(100_000);
        }

        // Proxy que não existe: se a consulta o usasse, falharia (e a prova negaria).
        foreach (['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy'] as $name) {
            putenv("{$name}=http://127.0.0.1:1");
        }
        putenv('NO_PROXY');

        Http::allowStrayRequests();
        Http::fake([
            'https://api-sandbox.asaas.com/v3/webhooks?*' => Http::response(['data' => []]),
            'https://api-sandbox.asaas.com/v3/webhooks'   => Http::response(['id' => 'wh_1']),
        ]);

        $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app', '--ngrok-api' => "http://127.0.0.1:{$port}"])
            ->assertExitCode(0);
    } finally {
        foreach ($previous as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }

        proc_terminate($server);
        proc_close($server);
        @unlink($router);
    }
});

it('API do ngrok fora do ar, com erro ou com resposta inválida: recusa (exit 2), mensagem clara e nada vai ao Asaas', function (Closure $response, string $message) {
    Http::fake([
        AWC_NGROK                                     => $response,
        'https://api-sandbox.asaas.com/v3/webhooks?*' => Http::response(['data' => []]),
        'https://api-sandbox.asaas.com/v3/webhooks'   => Http::response(['id' => 'wh_1']),
    ]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain($message)
        ->assertExitCode(2);

    awcAssertOnlyNgrokQueried();
})->with([
    'conexão recusada (túnel não subiu)' => [fn () => Http::failedConnection('refused'), 'Não consegui consultar a API local do ngrok'],
    'HTTP 500'                           => [fn () => Http::response('erro', 500), 'não devolveu a lista de túneis (HTTP 500)'],
    'HTTP 404'                           => [fn () => Http::response('', 404), 'não devolveu a lista de túneis (HTTP 404)'],
    'JSON inválido'                      => [fn () => Http::response('isto não é json', 200), 'não devolveu a lista de túneis'],
    'JSON sem tunnels'                   => [fn () => Http::response(['uri' => '/api/tunnels'], 200), 'não devolveu a lista de túneis'],
    'tunnels não é lista'                => [fn () => Http::response(['tunnels' => 'x'], 200), 'não devolveu a lista de túneis'],
    'redirecionamento (não é seguido)'   => [fn () => Http::response('', 302, ['Location' => 'https://evil.com/api/tunnels']), 'não devolveu a lista de túneis (HTTP 302)'],
]);

it('remove NÃO exige a prova (não envia segredo e roda na limpeza, com o ngrok possivelmente caído) e list também não', function () {
    Http::fake([
        AWC_NGROK                                     => Http::failedConnection('refused'),
        'https://api-sandbox.asaas.com/v3/webhooks?*' => Http::response(['data' => [awcWebhook('wh_mine', 'https://abc.ngrok-free.app' . AWC_PATH)]]),
        'https://api-sandbox.asaas.com/v3/webhooks/*' => Http::response(['deleted' => true, 'id' => 'wh_mine']),
    ]);

    $this->artisan('billing:asaas-webhook', ['action' => 'remove', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(0);
    $this->artisan('billing:asaas-webhook', ['action' => 'list'])->assertExitCode(0);

    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'http://127.0.0.1:4'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/wh_mine'));
});

it('fora de local/testing o comando aborta (exit 1) antes de qualquer chamada, em qualquer ação', function (string $env, string $action) {
    $this->app['env'] = $env;
    Http::fake();

    $this->artisan('billing:asaas-webhook', ['action' => $action, '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('só roda com APP_ENV local ou testing')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with(function () {
    foreach (['production', 'staging'] as $env) {
        foreach (['register', 'remove', 'list'] as $action) {
            yield "{$env} {$action}" => [$env, $action];
        }
    }
});

it('APP_ENV local também roda', function () {
    $this->app['env'] = 'local';
    awcFake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(0);
});

it('a allowlist da configuração manda: sufixo novo passa, o antigo deixa de passar', function () {
    config(['billing.sandbox_webhook_hosts' => ['tunel.exemplo.dev']]);
    awcFake();

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.tunel.exemplo.dev'])->assertExitCode(0);
    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])->assertExitCode(2);
});

it('o padrão da configuração é só domínio de túnel do ngrok', function () {
    expect(config('billing.sandbox_webhook_hosts'))->toBe(['.ngrok-free.app', '.ngrok.app', '.ngrok.io', '.ngrok-free.dev']);
});

it('o webhook é achado pela url com esquema e host sem caixa, mas o CAMINHO é exato: caixa ou prefixo diferentes não casam', function (string $stored, bool $matches) {
    awcFake([awcWebhook('wh_old', $stored)]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain($matches ? 'Webhook atualizado' : 'Webhook criado')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === ($matches ? 'PUT' : 'POST'));
    Http::assertNotSent(fn (Request $r) => $r->method() === ($matches ? 'POST' : 'PUT'));
})->with([
    'host em maiúsculas casa'    => ['HTTPS://ABC.NGROK-FREE.APP' . AWC_PATH, true],
    'barra final casa'           => ['https://abc.ngrok-free.app' . AWC_PATH . '/', true],
    'caminho com outra caixa'    => ['https://abc.ngrok-free.app/API/billing/webhooks/ASAAS', false],
    'caminho com prefixo a mais' => ['https://abc.ngrok-free.app/x' . AWC_PATH, false],
    'caminho com sufixo a mais'  => ['https://abc.ngrok-free.app' . AWC_PATH . '-antigo', false],
    'outra porta'                => ['https://abc.ngrok-free.app:8443' . AWC_PATH, false],
    'subdomínio a mais'          => ['https://x.abc.ngrok-free.app' . AWC_PATH, false],
    'com query'                  => ['https://abc.ngrok-free.app' . AWC_PATH . '?a=1', false],
]);

it('remove NÃO apaga webhook cujo caminho só difere pela caixa ou pelo prefixo', function () {
    awcFake([
        awcWebhook('wh_case', 'https://abc.ngrok-free.app/API/billing/webhooks/ASAAS'),
        awcWebhook('wh_prefix', 'https://abc.ngrok-free.app/x' . AWC_PATH),
        awcWebhook('wh_mine', 'https://ABC.ngrok-free.app' . AWC_PATH),
    ]);

    $this->artisan('billing:asaas-webhook', ['action' => 'remove', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('Webhook removido: wh_mine')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/wh_mine'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && (str_contains($r->url(), 'wh_case') || str_contains($r->url(), 'wh_prefix')));
});

it('remove apaga SÓ o webhook cuja url bate; sem ele não faz nada; repetir é seguro', function () {
    awcFake([awcWebhook('wh_mine', 'https://abc.ngrok-free.app' . AWC_PATH), awcWebhook('wh_other', 'https://outro.ngrok-free.app' . AWC_PATH)]);

    $this->artisan('billing:asaas-webhook', ['action' => 'remove', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('Webhook removido: wh_mine')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api-sandbox.asaas.com/v3/webhooks/wh_mine');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'wh_other'));
});

it('remove sem webhook com a url: nada a remover (repetir é seguro)', function () {
    awcFake([awcWebhook('wh_other', 'https://outro.ngrok-free.app' . AWC_PATH)]);
    $this->artisan('billing:asaas-webhook', ['action' => 'remove', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('nada a remover')
        ->assertExitCode(0);
});

it('list mostra os webhooks sem o authToken', function () {
    awcFake([awcWebhook('wh_1', 'https://abc.ngrok-free.app' . AWC_PATH, ['authToken' => AWC_TOKEN, 'interrupted' => true])]);

    $this->artisan('billing:asaas-webhook', ['action' => 'list'])
        ->expectsOutputToContain('wh_1')
        ->doesntExpectOutputToContain(AWC_TOKEN)
        ->assertExitCode(0);
});

it('ação desconhecida: exit 1', function () {
    Http::fake();

    $this->artisan('billing:asaas-webhook', ['action' => 'apagar-tudo'])->assertExitCode(1);

    Http::assertNothingSent();
});

it('erro da API (ex.: url recusada): exit 1 com os códigos, sem token', function () {
    Http::fake([
        AWC_NGROK                                     => Http::response(awcTunnels(['https://abc.ngrok-free.app'])),
        'https://api-sandbox.asaas.com/v3/webhooks?*' => Http::response(['data' => []]),
        'https://api-sandbox.asaas.com/v3/webhooks'   => Http::response(['errors' => [['code' => 'invalid_object', 'description' => 'A url informada é inválida.']]], 400),
    ]);

    $this->artisan('billing:asaas-webhook', ['action' => 'register', '--url' => 'https://abc.ngrok-free.app'])
        ->expectsOutputToContain('invalid_object')
        ->doesntExpectOutputToContain(AWC_TOKEN)
        ->assertExitCode(1);
});
