<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Billing\GatewayIntegrationException;
use App\Services\Billing\{GatewayCredentialResolver, GatewayRegistry};
use App\Services\Billing\Gateways\AsaasGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Registra/remove/lista o webhook do SANDBOX do Asaas apontando para a URL pública do túnel (ngrok) — o passo que o
 * `e2e/scripts/sandbox-run.sh` automatiza antes do Cypress. SÓ SANDBOX: a trava é a do `billing:gateway-environment`
 * (https + host exato do sandbox + chave `$aact_hmlg_` na credencial EFETIVA, a do Manager vence o .env); em produção
 * ou credencial desconhecida o comando aborta sem chamar a API. Só roda com APP_ENV local/testing.
 *
 *  - register: cria (POST /v3/webhooks) ou atualiza (PUT) o webhook cuja url é `--url` + a rota do app; o authToken é o
 *    webhook_secret do Manager (a mesma credencial com que o app valida a chegada), nunca impresso; enabled, não
 *    interrompido, apiVersion 3, envio SEQUENTIALLY e os eventos PAYMENT_/SUBSCRIPTION_/CHECKOUT_ que o app trata.
 *    Idempotente: repetir atualiza o mesmo webhook (e reativa um que o Asaas tenha interrompido). Como o Asaas entrega
 *    esse token ao host cadastrado, o `--url` precisa de PROVA DE TÚNEL LOCAL: ser o `public_url` exato de um túnel que
 *    a API do ngrok desta máquina (`--ngrok-api`, só loopback) lista com `config.addr` apontando para loopback — a
 *    allowlist de sufixos sozinha deixaria passar o ngrok-free.app de qualquer pessoa (domínio multi-tenant).
 *  - remove: apaga o webhook cuja url bate (nenhum = nada a fazer). Sem prova de túnel de propósito: não envia segredo
 *    (só apaga o que já aponta para essa url) e roda na limpeza, quando o ngrok pode ter caído — exigir a prova deixaria
 *    o webhook órfão. Segue valendo o formato + allowlist.
 *  - list: os webhooks da conta, sem o token.
 *
 * Exit: 0 ok · 1 falha (ambiente, API, credencial, secret) · 2 `--url` fora da allowlist ou sem prova de túnel local.
 */
class BillingAsaasWebhookCommand extends Command
{
    private const NAME = 'EasyEye E2E sandbox';

    /** Ambientes em que o comando roda (nunca em produção, mesmo com credencial de sandbox). */
    private const ENVIRONMENTS = ['local', 'testing'];

    /**
     * Loopback NUMÉRICO, o único aceito na API do ngrok e no `config.addr` do túnel: `localhost` é nome resolvível
     * (hosts, DNS, proxy) e pode apontar para outro lugar.
     */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1'];

    /** Exit de --url rejeitada ou sem prova de túnel (nada foi enviado ao Asaas): o `sandbox-run.sh` não tenta de novo nem acusa webhook esquecido. */
    public const INVALID_URL = 2;

    protected $signature = 'billing:asaas-webhook {action=register : register, remove ou list}
        {--url= : URL https do túnel (ex.: a do ngrok; só os domínios de billing.sandbox_webhook_hosts), sem porta nem caminho}
        {--ngrok-api=http://127.0.0.1:4040 : API local do ngrok (só loopback), consultada no register para provar que --url é um túnel desta máquina}';

    protected $description = 'SÓ SANDBOX: registra, remove ou lista o webhook do Asaas apontando para a URL pública do túnel (E2E)';

    public function handle(GatewayRegistry $registry, GatewayCredentialResolver $credentials): int
    {
        $action = (string) $this->argument('action');

        if (! app()->environment(self::ENVIRONMENTS)) {
            $this->error('ABORTADO: este comando só roda com APP_ENV local ou testing (atual: ' . app()->environment() . ').');

            return self::FAILURE;
        }

        if (! in_array($action, ['register', 'remove', 'list'], true)) {
            $this->error("Ação desconhecida '{$action}': use register, remove ou list.");

            return self::FAILURE;
        }

        if ($this->callSilent('billing:gateway-environment', ['gateway' => 'asaas']) !== self::SUCCESS) {
            $this->error('ABORTADO: a credencial efetiva do Asaas não é a do sandbox (billing:gateway-environment asaas). Este comando só roda no sandbox.');

            return self::FAILURE;
        }

        $gateway = $registry->get('asaas');

        if (! $gateway instanceof AsaasGateway) {
            $this->error('Gateway asaas indisponível.');

            return self::FAILURE;
        }

        try {
            return match ($action) {
                'list'   => $this->list($gateway),
                'remove' => $this->remove($gateway),
                default  => $this->register($gateway, $credentials),
            };
        } catch (GatewayIntegrationException $e) {
            $this->error('Falha na API do Asaas: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function list(AsaasGateway $gateway): int
    {
        $rows = collect($gateway->listWebhooks())->map(fn (array $w) => [
            $w['id'], $w['name'] ?? '', $w['url'] ?? '', ($w['enabled'] ?? false) ? 'sim' : 'não', ($w['interrupted'] ?? false) ? 'sim' : 'não', count((array) ($w['events'] ?? [])),
        ])->all();

        $rows === []
            ? $this->line('Nenhum webhook cadastrado.')
            : $this->table(['id', 'nome', 'url', 'ativo', 'interrompido', 'eventos'], $rows);

        return self::SUCCESS;
    }

    private function register(AsaasGateway $gateway, GatewayCredentialResolver $credentials): int
    {
        $url = $this->targetUrl(proveLocalTunnel: true);

        if ($url === null) {
            return self::INVALID_URL;
        }

        // A mesma credencial com que o app valida o webhook que chega (Manager vence o .env).
        $token = trim((string) ($credentials->resolveWebhookSecret('asaas') ?: config('billing.gateways.asaas.webhook_secret')));

        if (strlen($token) < 32) {
            $this->error('ABORTADO: o webhook_secret do Asaas (Manager > Gateways) está vazio ou tem menos de 32 caracteres (o Asaas exige 32 a 255).');

            return self::FAILURE;
        }

        $existing = collect($gateway->listWebhooks())->first(fn (array $w) => $this->sameUrl((string) ($w['url'] ?? ''), $url));
        $saved    = $gateway->saveWebhook($existing['id'] ?? null, [
            'name'        => self::NAME,
            'url'         => $url,
            'email'       => (string) config('mail.from.address', 'suporte@easyeye.app'),
            'enabled'     => true,
            'interrupted' => false,
            'apiVersion'  => 3,
            'authToken'   => $token,
            'sendType'    => 'SEQUENTIALLY',
            'events'      => $gateway->handledWebhookEvents(),
        ]);

        $this->info(($existing === null ? 'Webhook criado' : 'Webhook atualizado') . ": {$saved['id']} → {$url}");

        return self::SUCCESS;
    }

    private function remove(AsaasGateway $gateway): int
    {
        $url = $this->targetUrl(proveLocalTunnel: false);

        if ($url === null) {
            return self::INVALID_URL;
        }

        $matches = collect($gateway->listWebhooks())->filter(fn (array $w) => $this->sameUrl((string) ($w['url'] ?? ''), $url));

        if ($matches->isEmpty()) {
            $this->line("Nenhum webhook com a url {$url}: nada a remover.");

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($matches as $webhook) {
            if ($gateway->deleteWebhook((string) $webhook['id'])) {
                $this->info("Webhook removido: {$webhook['id']}");
            } else {
                $this->error("Não foi possível remover o webhook {$webhook['id']}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `--url` + o caminho da rota do app que recebe o webhook do Asaas. O register manda o `webhook_secret` como
     * `authToken` e o Asaas o entrega a QUALQUER host cadastrado, então a base só vale se for `https://<host>` — nada
     * além disso — e o host terminar num domínio de túnel da allowlist (`billing.sandbox_webhook_hosts`, sufixos
     * exatos): host próprio, IP, localhost/rede privada, porta, userinfo, caminho, query e fragmento caem fora.
     */
    private function targetUrl(bool $proveLocalTunnel): ?string
    {
        $base = strtolower(rtrim(trim((string) $this->option('url')), '/'));
        $host = parse_url($base, PHP_URL_HOST);

        // A base tem de ser EXATAMENTE `https://<host>`: a releitura barra porta, userinfo, caminho, `\`, espaço e
        // qualquer outro truque em que o parser do PHP e o do Asaas enxergariam hosts diferentes.
        if (! is_string($host) || $base !== 'https://' . $host || ! $this->isTunnelHost($host)) {
            $this->error('Informe --url com a URL https do túnel, sem porta, caminho nem credenciais (ex.: --url=https://abc123.ngrok-free.app); '
                . 'domínios aceitos: ' . implode(', ', $this->tunnelSuffixes()) . '.');

            return null;
        }

        if ($proveLocalTunnel && ! $this->isLocalTunnel($base)) {
            return null;
        }

        return $base . route('billing.webhooks', ['gateway' => 'asaas'], false);
    }

    /**
     * Prova de túnel local: a API do ngrok DESTA máquina (loopback, sem redirecionamento) lista um túnel cujo
     * `public_url` é exatamente `$base` e cujo `config.addr` aponta para loopback. Falha fechada: API fora do ar,
     * resposta que não é JSON com `tunnels`, túnel de outro host ou destino remoto = recusa, nada vai ao Asaas.
     */
    private function isLocalTunnel(string $base): bool
    {
        $api = $this->ngrokTunnelsUrl();

        if ($api === null) {
            $this->error('--ngrok-api inválida: use a API local do ngrok em loopback, sem credenciais nem caminho (ex.: --ngrok-api=http://127.0.0.1:4040).');

            return false;
        }

        try {
            // Sem redirecionamento e SEM proxy: `proxy => ''` desliga também HTTP(S)_PROXY do ambiente (o Guzzle só cai nele
            // quando a opção é null) — a consulta tem de ir direto ao loopback.
            $response = Http::timeout(3)->connectTimeout(2)->withoutRedirecting()->withOptions(['proxy' => ''])->acceptJson()->get($api);
        } catch (Throwable) {
            $this->error("Não consegui consultar a API local do ngrok em {$api}: suba o túnel (ngrok http <porta>) ou informe a porta certa em --ngrok-api.");

            return false;
        }

        // Decodifica SEM `associative`: objeto JSON vira stdClass e só array JSON vira array PHP. Com `json()` (assoc) o
        // objeto `{"tunnels": {"0": {…}}}` virava `[0 => …]` e passava por lista.
        $decoded = $response->successful() ? json_decode($response->body(), false, 16) : null;
        $tunnels = $decoded instanceof stdClass ? ($decoded->tunnels ?? null) : null;

        if (! is_array($tunnels)) {
            $this->error("A API do ngrok em {$api} não devolveu a lista de túneis (HTTP {$response->status()}): confira --ngrok-api.");

            return false;
        }

        foreach ($tunnels as $tunnel) {
            if ($this->tunnelProves($tunnel, $base)) {
                return true;
            }
        }

        $this->error("ABORTADO: {$base} não é um túnel desta máquina (nenhum túnel da API do ngrok em {$api} tem essa url pública apontando para loopback). O webhook_secret só é enviado a um túnel local.");

        return false;
    }

    /** Um túnel do ngrok é um OBJETO JSON (stdClass) com `public_url` (texto) e `config` (objeto) com `addr` (texto): qualquer outro tipo ou chave faltando não prova nada. */
    private function tunnelProves(mixed $tunnel, string $base): bool
    {
        if (! $tunnel instanceof stdClass || ! is_string($publicUrl = $tunnel->public_url ?? null) || ! ($config = $tunnel->config ?? null) instanceof stdClass) {
            return false;
        }

        return strtolower(rtrim(trim($publicUrl), '/')) === $base
            && $this->isLoopbackAddr($config->addr ?? null);
    }

    /** `--ngrok-api` → `http://<127.0.0.1|[::1]>[:porta]/api/tunnels`; qualquer outra forma (host remoto, https, userinfo, caminho…) = null. */
    private function ngrokTunnelsUrl(): ?string
    {
        $raw  = rtrim(trim((string) $this->option('ngrok-api')), '/');
        $host = parse_url($raw, PHP_URL_HOST);
        $port = parse_url($raw, PHP_URL_PORT);

        if (! is_string($host) || ! in_array(strtolower(trim($host, '[]')), self::LOOPBACK_HOSTS, true)
            || strtolower($raw) !== 'http://' . strtolower($host) . ($port !== null ? ':' . $port : '')) {
            return null;
        }

        return $raw . '/api/tunnels';
    }

    /** `config.addr` do ngrok (`http://127.0.0.1:8086`, `[::1]:8086`…): o destino local do túnel, por IP numérico. `localhost`, só porta, host remoto ou userinfo = false. */
    private function isLoopbackAddr(mixed $addr): bool
    {
        if (! is_string($addr) || ($addr = trim($addr)) === '') {
            return false;
        }

        $parts = parse_url(str_contains($addr, '://') ? $addr : 'http://' . $addr);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && in_array(strtolower(trim((string) ($parts['host'] ?? ''), '[]')), self::LOOPBACK_HOSTS, true);
    }

    private function isTunnelHost(string $host): bool
    {
        // parse_url devolve o IPv6 entre colchetes (`[::1]`): sem tirá-los a checagem de IP não enxergaria o endereço.
        $host = trim($host, '[]');

        // Qualquer IP literal (v4/v6, loopback, privado, link-local, IPv4 mapeado) e nome sem ponto (`localhost`) fica
        // fora ANTES da allowlist: o sufixo é o portão, esta checagem é o cinto.
        if ($host === '' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            return false;
        }

        foreach ($this->tunnelSuffixes() as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Sufixos com ponto inicial (`.ngrok-free.app`): exigem pelo menos um rótulo antes e não casam `evilngrok-free.app`. Lista vazia = nenhum host. */
    private function tunnelSuffixes(): array
    {
        return collect((array) config('billing.sandbox_webhook_hosts', []))
            ->map(fn ($suffix) => '.' . ltrim(strtolower(trim((string) $suffix)), '.'))
            ->reject(fn (string $suffix) => $suffix === '.')
            ->values()
            ->all();
    }

    /** Mesma url: esquema e host sem diferença de caixa (DNS), o resto — caminho e query — EXATAMENTE como está. */
    private function sameUrl(string $a, string $b): bool
    {
        return $this->canonicalUrl($a) === $this->canonicalUrl($b);
    }

    private function canonicalUrl(string $url): string
    {
        $url   = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($url, '/');
        }

        return strtolower($parts['scheme']) . '://'
            . (isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '')
            . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim($parts['path'] ?? '', '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
