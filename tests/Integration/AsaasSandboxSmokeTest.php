<?php

declare(strict_types=1);

/**
 * Prova REAL do contrato com o Asaas — SÓ no sandbox. Sem Http::fake: o AsaasGateway de verdade fala com
 * https://api-sandbox.asaas.com e cada passo confere os CAMPOS que o nosso código lê (os mesmos nomes de
 * AsaasGateway). A ideia é provar o contrato da API, não a UI nem o banco: o banco do app é só LIDO (a credencial
 * efetiva do Manager, pelo GatewayCredentialResolver REAL); nada é gravado nele (sem GatewayCustomer, sem RefreshDatabase;
 * o resto é HTTP e cache em memória).
 *
 * Fluxo (um `externalReference` único, `smoke-<uuid>`):
 *   1. cliente: payload (buildCustomerPayload) → POST /v3/customers → busca por cpfCnpj → GET do cadastro;
 *   2. cobrança Pix: createCharge → pixQrCode (encodedImage / payload / expirationDate) → fetchPayment;
 *   3. cobrança boleto: createCharge → identificationField (+ barCode) + bankSlipUrl + dueDate;
 *   4. Asaas Checkout (RECURRENT, minutesToExpire, callbacks do alvo SIGNUP) → estado → cancelHostedCheckoutStatus = canceled;
 *   5. limpeza: cancelCharge (DELETE) das cobranças e DELETE do cliente (sempre, mesmo se um passo falhar).
 *
 * TRAVA DUPLA (nunca contra produção) — sem as duas o teste é PULADO, sem nenhuma chamada de rede:
 *   a) ASAAS_SANDBOX_SMOKE=1 no ambiente do comando;
 *   b) `billing:gateway-environment asaas` (a MESMA regra do comando, rodando no próprio processo do teste) responder
 *      `sandbox`: https + host exato do sandbox + chave com o prefixo exato `$aact_hmlg_` na credencial EFETIVA. A
 *      credencial é a efetiva do app — a do Manager (banco) vence o .env, que fica vazio por desenho — então uma chave de
 *      PRODUÇÃO no banco nunca passa (a resposta é `production` e o teste pula). Uma 3ª trava dentro do gateway do teste
 *      recusa qualquer requisição fora de https + api-sandbox.asaas.com / sandbox.asaas.com.
 *   O endereço do sandbox vem do próprio teste (ASAAS_SMOKE_BASE_URL, https://api-sandbox.asaas.com): o .env.testing
 *   aponta ASAAS_BASE_URL para produção e não manda no smoke — quem decide é a chave `$aact_hmlg_` + a 3ª trava.
 *
 * Como rodar:
 *
 *   ASAAS_SANDBOX_SMOKE=1 ./vendor/bin/pest tests/Integration/AsaasSandboxSmokeTest.php
 *
 *   A credencial é a do Manager (/panel/manager/gateways), lida do banco que o Pest usar. ATENÇÃO: o Pest usa o banco de
 *   TESTE (phpunit.xml / .env.testing). Se a credencial do Manager está no banco da aplicação, aponte-o só para esta
 *   execução (leitura apenas; o teste não usa RefreshDatabase):  DB_DATABASE=<banco do app> ASAAS_SANDBOX_SMOKE=1 …
 *   Sem credencial no banco usado o teste pula com o motivo (`unknown`).
 *
 *   Opcional: ASAAS_SMOKE_CALLBACK_BASE=https://seu-dominio-cadastrado.example (base HTTPS dos callbacks do Checkout —
 *   o Asaas pode exigir um domínio cadastrado na conta; padrão https://app.easyeye.com.br). Em desenvolvimento use o
 *   domínio do ngrok (docs/internal/infra/ngrok-sandbox-asaas.md).
 *
 * Pré-requisitos da conta sandbox: chave Pix cadastrada (sem ela o Asaas recusa o QR Code do Pix — o passo 2 falha com
 * a mensagem do passo) e domínio dos callbacks aceito no Checkout. A chave nunca aparece na saída (mascarada).
 *
 * Isolamento do que o gateway usa: NENHUM redirect é seguido (allow_redirects=false em TODAS as chamadas + qualquer 3xx
 * derruba o passo — o token e o corpo nunca seguem para outro endereço) e o cache é o `array` do processo (o
 * CACHE_STORE do ambiente, ex.: database, não é tocado — nem pelo cache da credencial do resolvedor nem pelo controle
 * de 429 do gateway).
 *
 * Limpeza: cada objeto remoto é registrado ANTES do POST (referência) e removido em tentativas independentes, com o
 * estado real conferido (checkout canceled/expired; cobrança e cliente deleted=true). Criação com resposta perdida é
 * recuperada por externalReference; o que não puder ser removido/confirmado sai no aviso com ids + referência.
 *
 * tests/Integration só roda por caminho explícito ou na suíte completa; sem a trava, este arquivo apenas pula.
 */

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, HostedCheckoutDTO, PaymentInstructionsDTO};
use App\Rules\CpfCnpj;
use App\Services\Billing\{GatewayAlertService, GatewayCredentialResolver};
use App\Services\Billing\Gateways\AsaasGateway;
use App\Support\Billing\HostedCheckoutReference;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\{Arr, Str};
use Illuminate\Support\Facades\{Artisan, Cache, Http, URL};
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

uses(TestCase::class);

const ASAAS_SMOKE_SANDBOX_HOSTS = ['api-sandbox.asaas.com', 'sandbox.asaas.com'];

const ASAAS_SMOKE_BASE_URL = 'https://api-sandbox.asaas.com';

/** CPF válido (dígitos verificadores corretos) e não repetido — o sandbox valida o documento. */
function asaasSmokeCpf(): string
{
    do {
        $digits = array_map(static fn () => random_int(0, 9), range(1, 9));
    } while (count(array_unique($digits)) === 1);

    foreach ([10, 11] as $length) {
        $sum = 0;

        for ($i = 0; $i < $length - 1; $i++) {
            $sum += $digits[$i] * ($length - $i);
        }

        $check    = ($sum * 10) % 11;
        $digits[] = $check === 10 ? 0 : $check;
    }

    return implode('', $digits);
}

/**
 * CNPJ ALFANUMÉRICO válido (IN RFB 2.229/2024): 8 posições A–Z/0–9 + filial 0001 + 2 DVs numéricos (valor de cada
 * caractere = ord(c) − 48, mesmos pesos, módulo 11). Pelo menos uma letra na raiz.
 */
function asaasSmokeAlnumCnpj(): string
{
    $alphabet = array_merge(range('0', '9'), range('A', 'Z'));

    do {
        $root = '';

        for ($i = 0; $i < 8; $i++) {
            $root .= $alphabet[random_int(0, 35)];
        }
    } while (preg_match('/[A-Z]/', $root) !== 1);

    $chars = $root . '0001';

    foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += (ord($chars[$i]) - 48) * $weight;
        }

        $rest = $sum % 11;
        $chars .= $rest < 2 ? '0' : (string) (11 - $rest);
    }

    return $chars;
}

/** Troca a chave (e qualquer coisa com cara de chave Asaas) por [chave] — nada com a credencial sai em mensagem. */
function asaasSmokeMask(string $text, string $secret): string
{
    if ($secret !== '') {
        $text = str_replace($secret, '[chave]', $text);
    }

    return (string) preg_replace('/\$aact_[A-Za-z0-9_=\-]+/', '[chave]', $text);
}

/**
 * As travas do smoke, em ordem. null = pode rodar; senão, o motivo de pular (até aqui NENHUMA chamada de rede). O cache
 * em memória vem ANTES de qualquer leitura da credencial (o resolvedor guarda a credencial no cache) e o endereço do
 * sandbox é fixado aqui (o .env.testing aponta produção); a decisão é do `billing:gateway-environment` com a credencial
 * EFETIVA (Manager vence o .env).
 */
function asaasSmokeLocks(string $baseUrl = ASAAS_SMOKE_BASE_URL): ?string
{
    if (getenv('ASAAS_SANDBOX_SMOKE') !== '1') {
        return 'ASAAS_SANDBOX_SMOKE=1 ausente — smoke do sandbox do Asaas não roda por padrão.';
    }

    config(['cache.default' => 'array']);
    Cache::setDefaultDriver('array');
    Cache::flush();

    if (Cache::getDefaultDriver() !== 'array') {
        return 'não foi possível isolar o cache em memória — smoke não roda.';
    }

    config(['billing.gateways.asaas.base_url' => $baseUrl]);

    try {
        $exit        = Artisan::call('billing:gateway-environment', ['gateway' => 'asaas']);
        $environment = trim(Artisan::output());
    } catch (Throwable $e) {
        return 'não foi possível resolver a credencial efetiva do Asaas (' . class_basename($e) . ') — banco inacessível? O Pest usa o banco de teste (DB_DATABASE); a credencial do Manager precisa estar no banco usado.';
    }

    if ($exit !== 0 || $environment !== 'sandbox') {
        return "billing:gateway-environment asaas respondeu '{$environment}' — o smoke só roda contra o sandbox: a credencial efetiva (Manager vence o .env) precisa ser a de homologação (\$aact_hmlg_…) e estar no banco que o Pest usa (DB_DATABASE); 'unknown' = nenhuma credencial neste banco, 'production' = chave de produção ou endereço fora do sandbox.";
    }

    return null;
}

/** O AsaasGateway real, com a 3ª trava (host do sandbox em TODA requisição) e as chamadas cruas que o app não expõe. */
function asaasSmokeGateway(GatewayCredentialResolver $resolver): AsaasGateway
{
    return new class($resolver) extends AsaasGateway {
        public function raw(string $method, string $endpointKey, array $payload = [], array $replacements = []): Response
        {
            return $this->request($method, $endpointKey, $payload, $replacements);
        }

        public function customerBody(CustomerDTO $customer): array
        {
            return $this->buildCustomerPayload($customer);
        }

        /** GET /v3/checkouts/{id}: o app não usa; é só uma consulta de estado (pode não existir na API). */
        public function checkoutShow(string $id): Response
        {
            return Http::timeout(30)->withoutRedirecting()->withHeaders($this->authHeaders())->get($this->buildEndpoint('checkouts') . '/' . rawurlencode($id));
        }

        protected function buildEndpoint(string $endpointKey, array $replacements = []): string
        {
            $url = parent::buildEndpoint($endpointKey, $replacements);

            if (! str_starts_with($url, 'https://') || ! in_array(parse_url($url, PHP_URL_HOST), ASAAS_SMOKE_SANDBOX_HOSTS, true)) {
                throw new RuntimeException('smoke Asaas: requisição recusada, o endereço não é o do sandbox (' . parse_url($url, PHP_URL_HOST) . ').');
            }

            return $url;
        }
    };
}

/**
 * Limpeza do sandbox: cada objeto em tentativa INDEPENDENTE (um erro não impede os outros), com o estado real
 * conferido. Antes, o que um POST de resposta perdida pode ter criado sem devolver o id é recuperado por
 * externalReference — e, sem confirmação positiva (objeto encontrado e apagado), vira "objeto INCERTO", nunca é
 * descartado. Devolve os problemas (texto com ids + referência); vazio = nada ficou para trás.
 *
 * @param list<array{kind: string, reference: string, id: ?string, done: bool}> $objects
 *
 * @return list<string>
 */
function asaasSmokeCleanup(AsaasGateway $gateway, array $objects): array
{
    $problems = [];

    // 1) Criação sem id registrado (resposta perdida / timeout / erro depois do POST): procura pela referência. SEM
    //    confirmação positiva (objeto encontrado e depois apagado) a criação pendente NUNCA é descartada: um POST com
    //    timeout pode ser processado pelo Asaas DEPOIS da nossa busca — "não achei" não é "não existe".
    $kinds = ['customer' => 'cliente', 'charge' => 'cobrança', 'checkout' => 'checkout'];

    foreach ($objects as $object) {
        if ($object['done'] || filled($object['id'])) {
            continue;
        }

        $uncertain = "objeto INCERTO: {$kinds[$object['kind']]} (referência {$object['reference']})";

        try {
            if ($object['kind'] === 'checkout') {
                $problems[] = "{$uncertain} — o Checkout não tem busca por referência; expira sozinho (minutesToExpire) e deve ser conferido no painel do sandbox";

                continue;
            }

            $found = $gateway->raw('GET', $object['kind'] === 'customer' ? 'customers' : 'charges', ['externalReference' => $object['reference'], 'limit' => 100]);

            if (! $found->successful()) {
                $problems[] = "{$uncertain} — a busca por externalReference falhou (HTTP {$found->status()})";

                continue;
            }

            $rows      = $found->json('data');
            $confirmed = 0; // linhas com a referência EXATA que dão confirmação positiva
            $unclear   = 0; // linhas com a referência exata que NÃO dão (sem id válido e sem deleted === true)
            $idPattern = $object['kind'] === 'customer' ? '/^cus_[A-Za-z0-9_-]{1,60}$/' : '/^pay_[A-Za-z0-9_-]{1,60}$/';

            foreach (is_array($rows) ? $rows : [] as $row) {
                if (! is_array($row) || ($row['externalReference'] ?? null) !== $object['reference']) {
                    continue;
                }

                // Confirmado como apagado: SÓ o booleano true (a string 'false'/'true' ou qualquer outro valor não vale).
                if (($row['deleted'] ?? null) === true) {
                    $confirmed++;

                    continue;
                }

                // Qualquer outro estado, com id válido: vai para a limpeza abaixo, que remove e CONFERE deleted === true.
                if (is_string($row['id'] ?? null) && preg_match($idPattern, $row['id']) === 1) {
                    $objects[] = ['kind' => $object['kind'], 'reference' => $object['reference'], 'id' => $row['id'], 'done' => false]; // recuperado
                    $confirmed++;

                    continue;
                }

                $unclear++;
            }

            if ($confirmed === 0) {
                $problems[] = "{$uncertain} — a busca por externalReference respondeu 200 sem o objeto (ou sem data válida); o POST pode ter sido processado depois";
            }

            if ($unclear > 0) {
                $problems[] = "{$uncertain} — {$unclear} linha(s) com essa referência sem id válido e sem deleted:true; não dá para confirmar nem limpar";
            }
        } catch (Throwable $e) {
            $problems[] = "{$uncertain} — a busca falhou (" . class_basename($e) . ')';
        }
    }

    // 2) Checkouts → cobranças → cliente (o cliente só sai depois das cobranças), cada objeto por conta própria.
    foreach (['checkout', 'charge', 'customer'] as $kind) {
        foreach ($objects as $object) {
            if ($object['kind'] !== $kind || $object['done'] || blank($object['id'])) {
                continue;
            }

            $id    = (string) $object['id'];
            $label = "{$kind} {$id} (referência {$object['reference']})";

            try {
                if ($kind === 'checkout') {
                    $status = $gateway->cancelHostedCheckoutStatus($id);

                    if (! in_array($status, ['canceled', 'expired'], true)) {
                        $problems[] = "{$label}: estado '{$status}' (esperado canceled)" . ($status === 'paid' ? ' — PAGO, conferir no sandbox' : ' — pode seguir aberto');
                    }
                } elseif ($kind === 'charge') {
                    $canceled = $gateway->cancelCharge($id);
                    $show     = $gateway->raw('GET', 'payments', [], ['id' => $id]);

                    if (! $canceled) {
                        $problems[] = "{$label}: cancelCharge devolveu false (contrato: DELETE /payments/{id} → deleted:true)";
                    }

                    if (! ($show->successful() && $show->json('deleted') === true)) {
                        $problems[] = "{$label}: continua no Asaas (HTTP {$show->status()}, status " . ($show->json('status') ?? '?') . ')';
                    }
                } else {
                    $delete = $gateway->raw('DELETE', 'customer_show', [], ['id' => $id]);
                    $show   = $gateway->raw('GET', 'customer_show', [], ['id' => $id]);

                    if (! ($delete->successful() && $delete->json('deleted') === true)) {
                        $problems[] = "{$label}: DELETE /customers não confirmou deleted:true (HTTP {$delete->status()})";
                    }

                    if (! ($show->status() === 404 || ($show->successful() && $show->json('deleted') === true))) {
                        $problems[] = "{$label}: continua ativo no Asaas (HTTP {$show->status()})";
                    }
                }
            } catch (Throwable $e) {
                $problems[] = "{$label}: limpeza falhou (" . class_basename($e) . ': ' . $e->getMessage() . ')';
            }
        }
    }

    return $problems;
}

it('contrato real do Asaas no sandbox: cliente → Pix → boleto → Checkout hospedado (signup) → limpeza', function () {
    // ── Travas (flag explícita + credencial EFETIVA do Manager/.env == sandbox), cache em memória e endereço do sandbox. ──
    if (($reason = asaasSmokeLocks()) !== null) {
        $this->markTestSkipped($reason);
    }

    $resolver = app(GatewayCredentialResolver::class); // o REAL: Manager (banco) vence o .env
    // Chave recusada (401/403) não pode gerar alerta/e-mail/registro no app: o teste só falha com a mensagem do passo.
    $this->mock(GatewayAlertService::class, fn ($mock) => $mock->shouldReceive('credentialRejected')->andReturnNull());

    // ── Isolamento: nenhum redirect (token/corpo não seguem para outro endereço). O cache em memória já veio das travas. ──
    Http::globalOptions(['allow_redirects' => false]);
    Http::globalResponseMiddleware(function (ResponseInterface $response): ResponseInterface {
        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new RuntimeException('smoke Asaas: redirecionamento recusado (HTTP ' . $response->getStatusCode() . ') — o smoke nunca segue redirects.');
        }

        return $response;
    });

    $secret  = trim((string) ($resolver->resolveSecret('asaas') ?: config('billing.gateways.asaas.secret'))); // a mesma regra do gateway (só para mascarar)
    $gateway = asaasSmokeGateway($resolver);

    $uuid     = (string) Str::uuid();
    $ref      = "smoke-{$uuid}";
    $due      = CarbonImmutable::now('America/Sao_Paulo')->addDays(3)->toDateString();
    $cpf      = asaasSmokeCpf();
    $objects  = []; // todo objeto remoto, registrado ANTES do POST (referência) e com o id assim que a resposta traz
    $current  = 'início';
    $failure  = null;
    $problems = [];

    $register = function (string $kind, string $reference) use (&$objects): int {
        $objects[] = ['kind' => $kind, 'reference' => $reference, 'id' => null, 'done' => false];

        return array_key_last($objects);
    };
    $bind = function (int $key, mixed $id) use (&$objects): void {
        if (is_string($id) && $id !== '') {
            $objects[$key]['id'] = $id;
        }
    };

    $step = function (string $label) use (&$current): void {
        $current = $label;
        fwrite(STDERR, "  · {$label}\n");
    };

    $callbackBase = rtrim((string) (getenv('ASAAS_SMOKE_CALLBACK_BASE') ?: 'https://app.easyeye.com.br'), '/');

    try {
        // ── 1. Cliente ──────────────────────────────────────────────────────────────────────────────────────────────
        $step('1. cliente: payload, criação, busca por cpfCnpj e cadastro');

        $customer = new CustomerDTO(entityId: 'smoke', name: 'Smoke Asaas ' . substr($uuid, 0, 8), email: 'smoke+' . substr($uuid, 0, 8) . '@example.com', document: $cpf, phone: '11987654321', externalReference: $ref, address: ['zipcode' => '01310100', 'street' => 'Avenida Paulista', 'number' => '1000', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP']);
        // O endereço vem do CustomerDTO e o buildCustomerPayload do app o mapeia (address, addressNumber, province,
        // postalCode): é isso que permite anexar o cliente ao Asaas Checkout (sem ele o Asaas recusa com 400 invalid_object).
        $body = $gateway->customerBody($customer);

        expect($body['address'] ?? null)->toBe('Avenida Paulista')
            ->and($body['addressNumber'] ?? null)->toBe('1000')
            ->and($body['province'] ?? null)->toBe('Bela Vista')
            ->and($body['postalCode'] ?? null)->toBe('01310100')
            ->and($body['mobilePhone'] ?? null)->toBe('11987654321');

        expect($body['notificationDisabled'])->toBeTrue()
            ->and($body['cpfCnpj'])->toBe($cpf)
            ->and($body['externalReference'])->toBe($ref);

        $customerKey = $register('customer', $ref);
        $response    = $gateway->raw('POST', 'customers', $body);
        $customerId  = (string) ($response->json('id') ?? '');
        $bind($customerKey, $customerId);

        expect($response->successful())->toBeTrue("POST /customers HTTP {$response->status()}");

        $customerJson = (array) $response->json();

        expect($customerId)->toStartWith('cus_')
            ->and($customerJson['notificationDisabled'] ?? null)->toBeTrue() // o que upsertCustomer lê para marcar/ligar
            ->and(preg_replace('/\D/', '', (string) ($customerJson['cpfCnpj'] ?? '')))->toBe($cpf)
            ->and($customerJson['externalReference'] ?? null)->toBe($ref)
            ->and((bool) ($customerJson['deleted'] ?? false))->toBeFalse();

        $search = $gateway->raw('GET', 'customers', ['cpfCnpj' => $cpf]);
        expect($search->successful())->toBeTrue("GET /customers?cpfCnpj HTTP {$search->status()}");
        $row = collect((array) $search->json('data', []))->first(fn ($item) => is_array($item) && ($item['id'] ?? null) === $customerId);

        expect($row)->toBeArray()                                    // findExistingCustomer: data[].id
            ->and((bool) ($row['deleted'] ?? false))->toBeFalse()    // ... data[].deleted
            ->and($row['externalReference'] ?? null)->toBe($ref);    // ... data[].externalReference

        $show = $gateway->raw('GET', 'customer_show', [], ['id' => $customerId]);
        expect($show->successful())->toBeTrue()
            ->and($show->json('notificationDisabled'))->toBeTrue();  // ensureNotificationsDisabled

        // ── 2. Pix ──────────────────────────────────────────────────────────────────────────────────────────────────
        $step('2. cobrança Pix: criação, pixQrCode e fetchPayment');

        $pixKey = $register('charge', "{$ref}-pix");
        $pix    = $gateway->createCharge(new CreateChargeDTO(
            entityId: 'smoke',
            invoiceId: "{$ref}-pix",
            subscriptionId: $ref,
            customerId: $customerId,
            amount: 10.0,
            currency: 'BRL',
            description: "Smoke Asaas Pix {$ref}",
            dueDate: $due,
            paymentMethod: 'pix',
            knownChargeIds: [],
            lookupBeforeCreate: false,
        ));
        $bind($pixKey, $pix->externalPaymentId);

        expect($pix->success)->toBeTrue("createCharge(pix): {$pix->errorCode} {$pix->errorMessage}")
            ->and($pix->externalPaymentId)->toStartWith('pay_')
            ->and($pix->status)->toBe('pending')                     // normalizeAsaasPaymentStatus(PENDING)
            ->and($pix->amount)->toBe(10.0)
            ->and($pix->paymentUrl)->toMatch('#^https://([a-z0-9-]+\.)*asaas\.com/#i') // invoiceUrl
            ->and($pix->rawResponse['billingType'] ?? null)->toBe('PIX')
            ->and($pix->rawResponse['externalReference'] ?? null)->toBe("{$ref}-pix")
            ->and($pix->rawResponse['dueDate'] ?? null)->toBe($due);

        $pixInstructions = $gateway->paymentInstructions('pix', (string) $pix->externalPaymentId, (array) $pix->rawResponse);

        expect($pixInstructions)->toBeInstanceOf(PaymentInstructionsDTO::class)
            ->and($pixInstructions->pix['copy_paste'] ?? null)->toStartWith('000201')            // payload (copia-e-cola EMV)
            ->and(base64_decode((string) ($pixInstructions->pix['qr_code_base64'] ?? ''), true))->toStartWith("\x89PNG") // encodedImage
            ->and(CarbonImmutable::parse((string) ($pixInstructions->pix['expires_at'] ?? ''))->isFuture())->toBeTrue()  // expirationDate
            ->and($pixInstructions->paymentUrl)->toBe($pix->paymentUrl);

        $pixPayment = $gateway->fetchPayment((string) $pix->externalPaymentId);
        expect($pixPayment['status'] ?? null)->toBe('PENDING')
            ->and($pixPayment['billingType'] ?? null)->toBe('PIX')
            ->and((float) ($pixPayment['value'] ?? 0))->toBe(10.0)
            ->and($pixPayment['externalReference'] ?? null)->toBe("{$ref}-pix")
            ->and((bool) ($pixPayment['deleted'] ?? false))->toBeFalse();

        // ── 3. Boleto ───────────────────────────────────────────────────────────────────────────────────────────────
        $step('3. cobrança boleto: criação, identificationField, bankSlipUrl e dueDate');

        $boletoKey = $register('charge', "{$ref}-boleto");
        $boleto    = $gateway->createCharge(new CreateChargeDTO(
            entityId: 'smoke',
            invoiceId: "{$ref}-boleto",
            subscriptionId: $ref,
            customerId: $customerId,
            amount: 10.0,
            currency: 'BRL',
            description: "Smoke Asaas boleto {$ref}",
            dueDate: $due,
            paymentMethod: 'boleto',
            knownChargeIds: [],
            lookupBeforeCreate: false,
        ));
        $bind($boletoKey, $boleto->externalPaymentId);

        expect($boleto->success)->toBeTrue("createCharge(boleto): {$boleto->errorCode} {$boleto->errorMessage}")
            ->and($boleto->externalPaymentId)->toStartWith('pay_')
            ->and($boleto->status)->toBe('pending')
            ->and($boleto->rawResponse['billingType'] ?? null)->toBe('BOLETO')
            ->and($boleto->rawResponse['bankSlipUrl'] ?? null)->toMatch('#^https://([a-z0-9-]+\.)*asaas\.com/#i');

        $boletoInstructions = $gateway->paymentInstructions('boleto', (string) $boleto->externalPaymentId, (array) $boleto->rawResponse);
        $line               = $gateway->raw('GET', 'payment_boleto_line', [], ['id' => (string) $boleto->externalPaymentId]);
        expect($line->successful())->toBeTrue("GET identificationField HTTP {$line->status()}");

        // Linha digitável: a ÚNICA premissa fixa (47 dígitos) e o mapeamento identificationField → digitable_line.
        // barCode é opcional no DTO: só o mapeamento barCode → barcode, e só quando o Asaas o devolve.
        expect($boletoInstructions)->toBeInstanceOf(PaymentInstructionsDTO::class)
            ->and(preg_replace('/\D/', '', (string) ($boletoInstructions->boleto['digitable_line'] ?? '')))->toMatch('/^\d{47}$/')
            ->and($boletoInstructions->boleto['digitable_line'] ?? null)->toBe(trim((string) $line->json('identificationField')))
            ->and($boletoInstructions->boleto['pdf_url'] ?? null)->toBe($boleto->rawResponse['bankSlipUrl'])  // bankSlipUrl
            ->and($boletoInstructions->boleto['due_date'] ?? null)->toBe($due);                              // dueDate

        if (filled($line->json('barCode'))) {
            expect($boletoInstructions->boleto['barcode'] ?? null)->toBe(trim((string) $line->json('barCode')));
        }

        // ── 4. Checkout hospedado ───────────────────────────────────────────────────────────────────────────────────
        $step('4. Asaas Checkout (RECURRENT, callbacks do alvo signup): abertura, estado e cancelamento');

        URL::forceRootUrl($callbackBase);
        URL::forceScheme('https');
        $invoiceUuid = (string) Str::uuid();
        $checkoutRef = HostedCheckoutReference::make(null, $invoiceUuid);
        $callbacks   = [];

        foreach (['success', 'cancel', 'expired'] as $result) {
            $callbacks[$result] = route('signup-checkout.return', ['checkout_return' => $result, 'checkout_invoice' => $invoiceUuid]);
        }

        // 4a. Cliente SEM telefone/endereço anexado ao Checkout: o Asaas recusa (400 invalid_object citando o customer) e o
        //     gateway repete UMA vez sem o cliente — a sessão abre (a página coleta os dados) e o gateway diz o que faltava.
        $bareKey = $register('customer', "{$ref}-bare");
        $bareRes = $gateway->raw('POST', 'customers', $gateway->customerBody(new CustomerDTO(entityId: 'smoke', name: 'Smoke SemEndereco ' . substr($uuid, 0, 8), email: 'smoke-bare+' . substr($uuid, 0, 8) . '@example.com', document: asaasSmokeCpf(), phone: null, externalReference: "{$ref}-bare")));
        $bareId  = (string) ($bareRes->json('id') ?? '');
        $bind($bareKey, $bareId);

        expect($bareRes->successful())->toBeTrue("POST /customers (sem endereço) HTTP {$bareRes->status()}");

        $bareRef         = HostedCheckoutReference::make(null, (string) Str::uuid());
        $bareCheckoutKey = $register('checkout', $bareRef);
        $bare            = $gateway->createHostedCheckout(new HostedCheckoutDTO(
            entityId: 'smoke',
            invoiceId: $invoiceUuid,
            subscriptionId: null,
            customerId: $bareId,
            amount: 10.0,
            itemName: 'Smoke Asaas',
            description: "Smoke {$ref}",
            successUrl: $callbacks['success'],
            cancelUrl: $callbacks['cancel'],
            expiredUrl: $callbacks['expired'],
            externalReference: $bareRef,
            cycle: 'monthly',
            nextDueDate: CarbonImmutable::now('America/Sao_Paulo')->addDay()->toDateString(),
            minutesToExpire: 30,
        ));
        $bind($bareCheckoutKey, $bare->externalCheckoutId);

        expect($bare->success)->toBeTrue("checkout sem endereço HTTP {$bare->httpStatus}: {$bare->errorMessage}")
            ->and($bare->customerOmitted)->toBeTrue()                    // recusou o cliente e abriu sem ele
            ->and($bare->omittedReasons)->not->toBe([])                  // campos citados pelo Asaas (phone, address…)
            ->and(array_key_exists('customer', (array) $bare->rawResponse) ? $bare->rawResponse['customer'] : null)->toBeNull();
        expect($gateway->cancelHostedCheckoutStatus((string) $bare->externalCheckoutId))->toBe('canceled');
        $objects[$bareCheckoutKey]['done'] = true;

        $checkoutKey = $register('checkout', $checkoutRef);
        $checkout    = $gateway->createHostedCheckout(new HostedCheckoutDTO(
            entityId: 'smoke',
            invoiceId: $invoiceUuid,
            subscriptionId: null,
            customerId: $customerId,
            amount: 10.0,
            itemName: 'Smoke Asaas',
            description: "Smoke {$ref}",
            successUrl: $callbacks['success'],
            cancelUrl: $callbacks['cancel'],
            expiredUrl: $callbacks['expired'],
            externalReference: $checkoutRef,
            cycle: 'monthly',
            nextDueDate: CarbonImmutable::now('America/Sao_Paulo')->addDay()->toDateString(),
            minutesToExpire: 30,
        ));
        $bind($checkoutKey, $checkout->externalCheckoutId);

        expect($checkout->success)->toBeTrue("createHostedCheckout HTTP {$checkout->httpStatus}: {$checkout->errorMessage}")
            ->and($checkout->externalCheckoutId)->toMatch('/^[A-Za-z0-9_-]{1,64}$/') // o formato que cancelHostedCheckout aceita
            ->and($checkout->url)->toMatch('#^https://([a-z0-9-]+\.)*asaas\.com/#i')
            ->and($checkout->status)->toBe('ACTIVE')
            ->and($checkout->minutesToExpire)->toBe(30)
            ->and($checkout->customerOmitted)->toBeFalse()              // cliente COMPLETO: mantido no checkout
            ->and($checkout->omittedReasons)->toBe([]);

        // O VALOR: o item sai com quantity (obrigatório no Asaas) e value, e o Asaas os devolve como mandamos. A API não
        // tem GET do checkout (404); a prova de que o valor chega ao pagador é a PÁGINA hospedada (link da resposta).
        $item = (array) ($checkout->rawResponse['items'][0] ?? []);

        expect($item['quantity'] ?? null)->toBe(1)
            ->and((float) ($item['value'] ?? 0))->toBe(10.0);

        $pageUrl = (string) ($checkout->rawResponse['link'] ?? $checkout->url);

        expect(parse_url($pageUrl, PHP_URL_SCHEME))->toBe('https')
            ->and(in_array(parse_url($pageUrl, PHP_URL_HOST), ASAAS_SMOKE_SANDBOX_HOSTS, true))->toBeTrue('a página do checkout tem de ser do sandbox');

        $page = Http::timeout(30)->withoutRedirecting()->get($pageUrl);

        expect($page->status())->toBe(200)
            ->and($page->body())->toContain('Pagar R$ 10,00')                 // botão de pagamento com o valor
            ->and(substr_count($page->body(), 'R$ 10,00'))->toBeGreaterThanOrEqual(3); // botão + subtotal/total + item

        // O que o Asaas devolve sobre o que mandamos (quando ecoa): RECURRENT e os callbacks do alvo signup.
        if (is_array($checkout->rawResponse['chargeTypes'] ?? null)) {
            expect($checkout->rawResponse['chargeTypes'])->toContain('RECURRENT');
        }

        if (is_array($checkout->rawResponse['callback'] ?? null)) {
            expect((string) Arr::get($checkout->rawResponse, 'callback.successUrl'))->toContain('/signup-checkout/return')->toContain('checkout_return=success')
                ->and((string) Arr::get($checkout->rawResponse, 'callback.cancelUrl'))->toContain('checkout_return=cancel')
                ->and((string) Arr::get($checkout->rawResponse, 'callback.expiredUrl'))->toContain('checkout_return=expired');
        }

        // Consulta de estado (o app não consulta o checkout; se a API expõe o GET, tem de dizer ACTIVE antes e CANCELED depois).
        $before = $gateway->checkoutShow((string) $checkout->externalCheckoutId);
        $showOk = $before->successful();

        if ($showOk) {
            expect(strtoupper((string) $before->json('status')))->toBe('ACTIVE');
        }

        // O estado REAL em que o cancelamento deixa o link (a decisão de abrir outro checkout depende dele).
        expect($gateway->cancelHostedCheckoutStatus((string) $checkout->externalCheckoutId))->toBe('canceled');
        $objects[$checkoutKey]['done'] = true;

        if ($showOk) {
            $after = $gateway->checkoutShow((string) $checkout->externalCheckoutId);
            expect(in_array(strtoupper((string) $after->json('status')), ['CANCELED', 'CANCELLED'], true))->toBeTrue();
        }
    } catch (Throwable $e) {
        $failure = $e;
    } finally {
        // ── 5. Limpeza: SEMPRE (passou ou falhou), por objeto, conferindo o estado real; recupera criação sem id. ────
        $current = '5. limpeza (cancelCharge / DELETE do cliente / estado do checkout)';

        try {
            $problems = asaasSmokeCleanup($gateway, $objects);
        } catch (Throwable $cleanupError) {
            $problems = ['limpeza interrompida (' . class_basename($cleanupError) . '): confira no sandbox tudo com a referência ' . $ref];
        }

        URL::forceRootUrl(null);
        URL::forceScheme(null);
    }

    if ($failure !== null || $problems !== []) {
        $message = $failure !== null
            ? "[passo: {$current}] " . class_basename($failure) . ': ' . $failure->getMessage() . ' (' . basename($failure->getFile()) . ':' . $failure->getLine() . ')'
            : '';

        if ($problems !== []) {
            $message .= ($message !== '' ? ' ' : '[passo: 5. limpeza] ') . 'SANDBOX NÃO ESTÁ LIMPO (referência ' . $ref . '; apague à mão o que restar): ' . implode('; ', $problems) . '.';
        }

        // Sem encadear o erro original: a mensagem mascarada é a única saída (nada com a chave vai para o relatório).
        throw new RuntimeException(asaasSmokeMask($message, $secret));
    }
});

it('CNPJ alfanumérico: o cliente e a assinatura do sandbox aceitam o canônico COM as letras (e recusam o de DV errado) — limpeza no fim', function () {
    if (($reason = asaasSmokeLocks()) !== null) {
        $this->markTestSkipped($reason);
    }

    $resolver = app(GatewayCredentialResolver::class);
    $this->mock(GatewayAlertService::class, fn ($mock) => $mock->shouldReceive('credentialRejected')->andReturnNull());

    Http::globalOptions(['allow_redirects' => false]);
    Http::globalResponseMiddleware(function (ResponseInterface $response): ResponseInterface {
        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new RuntimeException('smoke Asaas: redirecionamento recusado (HTTP ' . $response->getStatusCode() . ') — o smoke nunca segue redirects.');
        }

        return $response;
    });

    $secret  = trim((string) ($resolver->resolveSecret('asaas') ?: config('billing.gateways.asaas.secret')));
    $gateway = asaasSmokeGateway($resolver);

    $uuid           = (string) Str::uuid();
    $ref            = "smoke-alnum-{$uuid}";
    $cnpj           = asaasSmokeAlnumCnpj();
    $masked         = substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
    $wrong          = substr($cnpj, 0, 12) . (substr($cnpj, 12, 2) === '00' ? '11' : '00'); // DV errado de propósito
    $objects        = [];
    $subscriptionId = null;
    $current        = 'início';
    $failure        = null;
    $problems       = [];

    try {
        // ── 1. A regra do app concorda com o Asaas ──────────────────────────────────────────────────────────────────
        $current = '1. CpfCnpj do app aceita o gerado e recusa o de DV errado';
        expect(CpfCnpj::isValid($cnpj))->toBeTrue("CNPJ alfanumérico gerado {$cnpj} deveria ser válido")
            ->and(CpfCnpj::isValid($masked))->toBeTrue()
            ->and(CpfCnpj::isValid($wrong))->toBeFalse();

        // ── 2. Payload do cliente: canônico, sem pontuação, COM as letras ────────────────────────────────────────────
        $current  = '2. payload do cliente (buildCustomerPayload) com o CNPJ mascarado';
        $customer = new CustomerDTO(entityId: 'smoke', name: 'Smoke Alnum ' . substr($uuid, 0, 8), email: 'smoke+' . substr($uuid, 0, 8) . '@example.com', document: $masked, phone: '11987654321', externalReference: $ref);
        $body     = $gateway->customerBody($customer);

        expect($body['cpfCnpj'])->toBe($cnpj)->and($body['cpfCnpj'])->toMatch('/[A-Z]/');

        // ── 3. O Asaas aceita e devolve o alfanumérico; a busca por cpfCnpj (findExistingCustomer) o acha ────────────
        $current                     = '3. POST /customers com o alfanumérico, busca por cpfCnpj e cadastro';
        $customerKey                 = count($objects);
        $objects[]                   = ['kind' => 'customer', 'reference' => $ref, 'id' => null, 'done' => false];
        $created                     = $gateway->raw('POST', 'customers', $body);
        $customerId                  = (string) ($created->json('id') ?? '');
        $objects[$customerKey]['id'] = $customerId !== '' ? $customerId : null;

        expect($created->successful())->toBeTrue("POST /customers HTTP {$created->status()} com o CNPJ alfanumérico")
            ->and($customerId)->toStartWith('cus_')
            ->and(strtoupper((string) $created->json('cpfCnpj')))->toBe($cnpj);

        $search = $gateway->raw('GET', 'customers', ['cpfCnpj' => $cnpj]);
        $row    = collect((array) $search->json('data', []))->first(fn ($item) => is_array($item) && ($item['id'] ?? null) === $customerId);

        expect($search->successful())->toBeTrue("GET /customers?cpfCnpj HTTP {$search->status()}")
            ->and($row)->toBeArray()
            ->and($row['externalReference'] ?? null)->toBe($ref);

        // ── 4. DV errado: o Asaas recusa (a regra do app existe para o usuário ver isso ANTES) ───────────────────────
        $current = '4. POST /customers com DV errado é recusado';
        // Registrado ANTES do POST (referência): se o Asaas aceitar e a resposta se perder, a limpeza o acha por externalReference
        // (ou reporta INCERTO). Só a recusa conclusiva (HTTP 400) o dá por não criado.
        $wrongKey  = count($objects);
        $objects[] = ['kind' => 'customer', 'reference' => "{$ref}-errado", 'id' => null, 'done' => false];
        $refused   = $gateway->raw('POST', 'customers', [...$body, 'cpfCnpj' => $wrong, 'externalReference' => "{$ref}-errado"]);

        if ($refused->successful() && filled($refused->json('id'))) {
            $objects[$wrongKey]['id'] = (string) $refused->json('id'); // limpa mesmo assim

            throw new RuntimeException('o Asaas ACEITOU um CNPJ alfanumérico de DV errado — a premissa da regra mudou.');
        }

        expect($refused->status())->toBe(400);
        $objects[$wrongKey]['done'] = true; // recusa conclusiva: nada foi criado

        // ── 5. A ASSINATURA (o que o cadastro cria) nasce com esse cliente ──────────────────────────────────────────
        $current      = '5. createSubscription com o cliente de CNPJ alfanumérico';
        $subscription = $gateway->createSubscription(new CreateSubscriptionDTO(
            entityId: 'smoke',
            subscriptionId: $ref,
            planId: 'smoke-plan',
            customerId: $customerId,
            amount: 10.0,
            currency: 'BRL',
            interval: 'month',
            intervalCount: 1,
            description: "Smoke Asaas alfanumérico {$ref}",
            firstDueDate: CarbonImmutable::now('America/Sao_Paulo')->addDays(3)->toDateString(),
        ));
        $subscriptionId = $subscription->externalSubscriptionId;

        expect($subscription->success)->toBeTrue("createSubscription: {$subscription->errorMessage}")
            ->and($subscriptionId)->toStartWith('sub_');
    } catch (Throwable $e) {
        $failure = $e;
    } finally {
        $current = '6. limpeza (assinatura e clientes)';

        // 1) A assinatura e 2) os clientes são tentativas INDEPENDENTES: uma exceção na busca/cancelamento/conferência da
        //    assinatura nunca pula a limpeza dos objetos — todas as falhas são agregadas.
        try {
            // Assinatura: pelo id; se a resposta se perdeu, pela referência (externalReference = $ref).
            if ($subscriptionId === null) {
                $found = $gateway->raw('GET', 'subscriptions', ['externalReference' => $ref, 'limit' => 100]);

                foreach ((array) $found->json('data') as $row) {
                    if (is_array($row) && ($row['externalReference'] ?? null) === $ref && ($row['deleted'] ?? false) !== true && filled($row['id'] ?? null)) {
                        $subscriptionId = (string) $row['id'];
                    }
                }
            }

            if ($subscriptionId !== null) {
                $canceled = $gateway->cancelSubscription(new CancelSubscriptionDTO(entityId: 'smoke', subscriptionId: $ref, externalSubscriptionId: $subscriptionId));

                // O Asaas confirma a remoção com atraso (a 1ª leitura logo depois do DELETE já devolveu deleted:false): até 5 leituras.
                $removed = false;

                for ($attempt = 1; $attempt <= 5 && ! $removed; $attempt++) {
                    $show    = $gateway->raw('GET', 'subscription_show', [], ['id' => $subscriptionId]);
                    $removed = $show->status() === 404 || ($show->successful() && $show->json('deleted') === true);

                    if (! $removed) {
                        sleep(1);
                    }
                }

                if (! $removed) {
                    $problems[] = "assinatura {$subscriptionId} continua ativa (cancelSubscription success=" . var_export($canceled->success, true) . ", HTTP {$show->status()})";
                }
            }
        } catch (Throwable $subscriptionError) {
            $problems[] = 'limpeza da assinatura falhou (' . class_basename($subscriptionError) . ')' . ($subscriptionId !== null ? " — confira {$subscriptionId}" : " — confira a referência {$ref}");
        }

        try {
            $problems = [...$problems, ...asaasSmokeCleanup($gateway, $objects)];
        } catch (Throwable $cleanupError) {
            $problems[] = 'limpeza dos clientes interrompida (' . class_basename($cleanupError) . '): confira no sandbox tudo com a referência ' . $ref;
        }
    }

    if ($failure !== null || $problems !== []) {
        $message = $failure !== null
            ? "[passo: {$current}] " . class_basename($failure) . ': ' . $failure->getMessage() . ' (' . basename($failure->getFile()) . ':' . $failure->getLine() . ')'
            : '';

        if ($problems !== []) {
            $message .= ($message !== '' ? ' ' : '[passo: 6. limpeza] ') . 'SANDBOX NÃO ESTÁ LIMPO (referência ' . $ref . '; apague à mão o que restar): ' . implode('; ', $problems) . '.';
        }

        throw new RuntimeException(asaasSmokeMask($message, $secret));
    }
});
