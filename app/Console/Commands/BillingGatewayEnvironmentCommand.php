<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\GatewayCredentialResolver;
use Illuminate\Console\Command;

/**
 * Só leitura: diz em qual AMBIENTE o gateway vai operar AGORA — a credencial efetiva que o app usaria
 * (a do Manager, se houver; senão a do .env — a mesma regra de AbstractHttpGateway::resolveSecret()) e o
 * endereço base configurado. Imprime "sandbox", "production" ou "unknown" e NUNCA a chave.
 *
 * Sandbox = https + host EXATO do sandbox (lista fechada) E chave com o prefixo exato de homologação
 * ($aact_hmlg_ no Asaas). Substring não serve: api-sandbox.asaas.com.evil.io ou uma chave de produção que
 * contenha "hmlg" passariam. Qualquer outra combinação (inclusive sandbox pela metade) é "production": quem usa
 * isto para liberar um teste que cobra de verdade (E2E contra o sandbox) só pode passar com certeza.
 * Saída: 0 = sandbox; 1 = production ou unknown (gateway sem credencial/endereço ou sem sandbox conhecido).
 */
class BillingGatewayEnvironmentCommand extends Command
{
    /** Sandbox conhecido por gateway: hosts exatos (https) e prefixo exato da chave de homologação. */
    private const SANDBOX = [
        'asaas' => ['hosts' => ['api-sandbox.asaas.com', 'sandbox.asaas.com'], 'key_prefix' => '$aact_hmlg_'],
    ];

    /**
     * Partes do endereço que NÃO podem existir (cada uma, isoladamente, já reprova): só scheme + host [+ path] vale.
     * `isset($parts['user'], $parts['port'])` só é true quando TODAS existem — porta ou usuário sozinhos passavam.
     */
    private const FORBIDDEN_URL_PARTS = ['user', 'pass', 'port', 'query', 'fragment'];

    protected $signature = 'billing:gateway-environment {gateway=asaas : Código do gateway (asaas, mercadopago, …)}';

    protected $description = 'Imprime sandbox ou production para a credencial efetiva do gateway (sem mostrar a chave); sai 0 só no sandbox';

    public function handle(GatewayCredentialResolver $credentials): int
    {
        $code    = mb_strtolower(trim((string) $this->argument('gateway')));
        $baseUrl = trim((string) config("billing.gateways.{$code}.base_url"));
        $secret  = $credentials->resolveSecret($code) ?: trim((string) config("billing.gateways.{$code}.secret"));

        if ($baseUrl === '' || $secret === '') {
            $this->line('unknown');

            return self::FAILURE;
        }

        $parts   = parse_url($baseUrl) ?: [];
        $sandbox = isset(self::SANDBOX[$code])
            && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(mb_strtolower((string) ($parts['host'] ?? '')), self::SANDBOX[$code]['hosts'], true)
            && array_intersect_key($parts, array_flip(self::FORBIDDEN_URL_PARTS)) === []
            && str_starts_with($secret, self::SANDBOX[$code]['key_prefix']);

        $this->line($sandbox ? 'sandbox' : 'production');

        return $sandbox ? self::SUCCESS : self::FAILURE;
    }
}
