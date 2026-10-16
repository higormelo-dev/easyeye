<?php

declare(strict_types=1);

use App\Services\Billing\GatewayCredentialResolver;

/**
 * billing:gateway-environment — só leitura: sandbox (exit 0) ou production/unknown (exit 1), nunca a chave.
 */
function gweSetAsaas(string $baseUrl, ?string $secret): void
{
    config(['billing.gateways.asaas.base_url' => $baseUrl, 'billing.gateways.asaas.secret' => $secret]);
}

/** Resolver que devolve a credencial "do Manager" no lugar do .env. */
function gweManagerSecret(?string $secret): void
{
    app()->instance(GatewayCredentialResolver::class, new class($secret) extends GatewayCredentialResolver {
        public function __construct(private readonly ?string $managerSecret)
        {
            parent::__construct();
        }

        public function resolveSecret(string $gatewayCode): ?string
        {
            return $this->managerSecret;
        }
    });
}

it('sandbox: endereço de sandbox E chave hmlg → imprime "sandbox" e sai 0, sem mostrar a chave', function () {
    gweSetAsaas('https://api-sandbox.asaas.com', '$aact_hmlg_000000000000000000000000_SEGREDO');

    $this->artisan('billing:gateway-environment')
        ->expectsOutput('sandbox')
        ->doesntExpectOutputToContain('SEGREDO')
        ->assertExitCode(0);
});

it('sandbox: aceita só scheme + host [+ path], nos dois hosts do sandbox', function (string $baseUrl) {
    gweSetAsaas($baseUrl, '$aact_hmlg_000000000000000000000000_SEGREDO');

    $this->artisan('billing:gateway-environment')
        ->expectsOutput('sandbox')
        ->doesntExpectOutputToContain('SEGREDO')
        ->assertExitCode(0);
})->with([
    'api-sandbox.asaas.com'      => ['https://api-sandbox.asaas.com'],
    'sandbox.asaas.com'          => ['https://sandbox.asaas.com'],
    'sandbox.asaas.com com path' => ['https://sandbox.asaas.com/v3'],
    'host em maiúsculas'         => ['HTTPS://SANDBOX.ASAAS.COM'],
]);

it('production: endereço de produção, ou chave que não é de homologação, ou sandbox pela metade → "production" e sai 1', function (string $baseUrl, string $secret) {
    gweSetAsaas($baseUrl, $secret);

    $this->artisan('billing:gateway-environment')
        ->expectsOutput('production')
        ->doesntExpectOutputToContain('SEGREDO')
        ->assertExitCode(1);
})->with([
    'produção de verdade'                    => ['https://api.asaas.com', '$aact_prod_000000_SEGREDO'],
    'endereço sandbox com chave de produção' => ['https://api-sandbox.asaas.com', '$aact_prod_000000_SEGREDO'],
    'chave hmlg com endereço de produção'    => ['https://api.asaas.com', '$aact_hmlg_000000_SEGREDO'],
    'host falso com "sandbox" no nome'       => ['https://sandbox.asaas.com.evil.io', '$aact_hmlg_000000_SEGREDO'],
    'host falso api-sandbox…evil'            => ['https://api-sandbox.asaas.com.evil.io', '$aact_hmlg_000000_SEGREDO'],
    'credencial embutida no endereço'        => ['https://api-sandbox.asaas.com@evil.io', '$aact_hmlg_000000_SEGREDO'],
    'porta isolada'                          => ['https://api-sandbox.asaas.com:8443', '$aact_hmlg_000000_SEGREDO'],
    'porta isolada no host sandbox.asaas'    => ['https://sandbox.asaas.com:8443', '$aact_hmlg_000000_SEGREDO'],
    'usuário isolado (userinfo)'             => ['https://usuario@api-sandbox.asaas.com', '$aact_hmlg_000000_SEGREDO'],
    'usuário e senha'                        => ['https://usuario:senha@api-sandbox.asaas.com', '$aact_hmlg_000000_SEGREDO'],
    'senha vazia após o usuário'             => ['https://usuario:@api-sandbox.asaas.com', '$aact_hmlg_000000_SEGREDO'],
    'query string'                           => ['https://api-sandbox.asaas.com/?host=evil.io', '$aact_hmlg_000000_SEGREDO'],
    'fragmento'                              => ['https://api-sandbox.asaas.com/#evil', '$aact_hmlg_000000_SEGREDO'],
    'esquema http (não https)'               => ['http://api-sandbox.asaas.com', '$aact_hmlg_000000_SEGREDO'],
    'chave de PRODUÇÃO que contém hmlg'      => ['https://api-sandbox.asaas.com', '$aact_prod_hmlg_000000_SEGREDO'],
    'chave sem o prefixo exato'              => ['https://api-sandbox.asaas.com', 'xx$aact_hmlg_000000_SEGREDO'],
]);

it('a credencial EFETIVA vale: a do Manager vence o .env (sandbox no .env, produção no Manager = production)', function () {
    gweSetAsaas('https://api-sandbox.asaas.com', '$aact_hmlg_000000_ENV');
    gweManagerSecret('$aact_prod_000000_SEGREDO_DO_MANAGER');

    $this->artisan('billing:gateway-environment')
        ->expectsOutput('production')
        ->doesntExpectOutputToContain('SEGREDO_DO_MANAGER')
        ->assertExitCode(1);

    // E o inverso: .env de produção, Manager em homologação → é o Manager que o app usaria.
    gweSetAsaas('https://api-sandbox.asaas.com', '$aact_prod_000000_ENV');
    gweManagerSecret('$aact_hmlg_000000_SEGREDO_DO_MANAGER');

    $this->artisan('billing:gateway-environment')->expectsOutput('sandbox')->assertExitCode(0);
});

it('sem credencial ou sem endereço, ou gateway desconhecido: "unknown" e sai 1', function () {
    gweSetAsaas('https://api-sandbox.asaas.com', null);
    gweManagerSecret(null);
    $this->artisan('billing:gateway-environment')->expectsOutput('unknown')->assertExitCode(1);

    gweSetAsaas('', '$aact_hmlg_000000_SEGREDO');
    $this->artisan('billing:gateway-environment')->expectsOutput('unknown')->assertExitCode(1);

    $this->artisan('billing:gateway-environment', ['gateway' => 'gateway-que-nao-existe'])->expectsOutput('unknown')->assertExitCode(1);
});

it('o argumento é o código do gateway (padrão asaas) e a caixa não importa', function () {
    gweSetAsaas('https://api-sandbox.asaas.com', '$aact_hmlg_000000_X');

    $this->artisan('billing:gateway-environment', ['gateway' => 'ASAAS'])->expectsOutput('sandbox')->assertExitCode(0);
});
