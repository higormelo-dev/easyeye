<?php

// Remove do Asaas SANDBOX e do banco de DEV o que o spec e2e/cypress/e2e/sandbox/signup-checkout-sandbox.cy.js cria:
// cada caso registra um usuário `cy-sandbox-<nonce>-<caso>@example.test` e o /register gera a clínica
// "Clínica Sandbox <caso>" dele (mais assinatura, fatura, vínculo, checkout hospedado e logs de billing).
//
//   # a rodada do spec (o after() faz isto sozinho): SÓ os e-mails que a rodada registrou no arquivo de estado
//   SANDBOX_CLEAN_STATE=e2e/cypress/.sandbox-run/<nonce>.json php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"
//   # restos de rodadas mortas (sem arquivo de estado): varredura ampla por padrão de e-mail/nome, OPT-IN explícito
//   SANDBOX_CLEAN_ALL=1 php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"
//   # qualquer um dos dois com SANDBOX_CLEAN_DRY_RUN=1 só lista o que apagaria (as leituras no Asaas acontecem)
//
// GUARDAS (qualquer uma falhando aborta com exit 1, nesta ordem e ANTES de qualquer chamada ao Asaas, de qualquer
// leitura da credencial (que toca banco/cache) e de qualquer DELETE):
//   1. APP_ENV local/testing;
//   2. conexão de banco EFETIVA: TODOS os destinos (DB_URL e suas opções, host, database, read[], write[], mais a conexão
//      viva) em host local e num banco da lista E2E_ALLOWED_DATABASES (easyeye, easyeye_test, easyeye_e2e) — o mesmo
//      guard do clean-eye-images.php (um app local apontado a um banco de produção apagaria clínicas de verdade);
//   3. credencial EFETIVA do Asaas = sandbox (`billing:gateway-environment asaas`: https + host do sandbox + chave
//      `$aact_hmlg_`; a do Manager vence o .env).
//
// O QUE ENTRA (nada fora disto é tocado):
//   - modo da rodada (padrão): usuários cujo e-mail está no arquivo de estado da rodada (e2e/cypress/.sandbox-run/*.json,
//     gravado pelo spec no momento em que gera cada e-mail), criados DEPOIS do início da rodada. Uma conta que já existia
//     e só coincide com o padrão NÃO é tocada. Sem arquivo de estado (e sem SANDBOX_CLEAN_ALL=1) ABORTA sem apagar nada;
//   - modo SANDBOX_CLEAN_ALL=1: todos os usuários `cy-sandbox-%@example.test`;
//   - nos dois modos: a clínica só entra se TODOS os usuários dela são desse conjunto E o nome começa com
//     "Clínica Sandbox " (as duas condições); um usuário só é apagado se TODAS as clínicas dele entram. O que não entra
//     aparece como IGNORADO.
//
// ORDEM: Asaas primeiro, banco depois. No Asaas, por clínica: clientes por externalReference = id da clínica (o que o
// AsaasGateway grava), suas assinaturas, as sessões do Checkout hospedado (billing_hosted_checkouts: cancela a sessão
// e, se foi paga, a recorrência que nasceu dela) — com listas validadas e PAGINADAS, cada DELETE CONFIRMADO (deleted:true
// ou 404). Qualquer incerteza (lista inválida, status inesperado, remoção não confirmada, sessão paga sem recorrência
// registrada) MANTÉM a clínica e o usuário no banco, para a próxima execução (idempotente), e o script sai com exit 2.
// TRAVA ANTES DO ASAAS: o script abre uma transação, trava (FOR UPDATE) clínicas (ordenadas por id) e depois usuários,
// decide a elegibilidade JÁ SOB a trava e a segura até o commit final — um vínculo novo (usuário real na clínica, usuário
// de teste em outra clínica) espera esta transação, então nunca nasce entre a decisão e os DELETEs, e só as clínicas
// elegíveis chegam a ser tocadas no Asaas. É script de teste em banco de dev: a transação aberta durante as chamadas
// remotas (segundos) é aceitável. A revalidação final dentro da mesma transação é mantida (cinto e suspensório). Sem
// nada a limpar: "nada a limpar", exit 0.
//
// No banco o caminho é o do clean-billing.php: as FKs para `entities` são quase todas ON DELETE CASCADE (assinaturas,
// faturas, vínculos, checkouts hospedados...); as sem cascata (notices, schedule_events, waiting_list, doctors, ai_runs)
// saem à mão. Os billing_logs da clínica são apagados também (a FK é SET NULL e ficariam órfãos).

use App\Services\Billing\GatewayRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\{Arr, Collection, ConfigurationUrlParser};
use Illuminate\Support\Facades\{Artisan, DB};

if (! function_exists('sscDatabaseTargets')) {
    /**
     * Todo destino que a conexão padrão pode alcançar, já RESOLVIDO como o Laravel o resolve (cópia do guard de
     * clean-eye-images.php): DB_URL passa pelo ConfigurationUrlParser do DatabaseManager (as opções da QUERY sobrepõem o
     * caminho da URL) e read/write (única entrada ou LISTA, cada uma com host e database próprios) são mesclados sobre a
     * base como o ConnectionFactory faz. A conexão viva é conferida DEPOIS (sscGuard), mas só ela não basta: o
     * Connection guarda apenas o destino de escrita sorteado e perde os de leitura.
     *
     * @return array<string, array<string, mixed>> rótulo => configuração do destino
     */
    function sscDatabaseTargets(): array
    {
        $name = DB::getDefaultConnection();
        $raw  = config("database.connections.{$name}");

        if (! is_array($raw)) {
            sscAbortGuard("a conexão padrão '{$name}' não tem configuração.");
        }

        try {
            $resolved = (new ConfigurationUrlParser())->parseConfiguration($raw);
        } catch (Throwable $e) {
            sscAbortGuard('não foi possível resolver a URL da conexão (' . class_basename($e) . ').');
        }

        $base    = Arr::except($resolved, ['read', 'write']);
        $targets = ['conexão resolvida' => $base];

        foreach (['read', 'write'] as $type) {
            if (! array_key_exists($type, $resolved)) {
                continue;
            }

            $entries = $resolved[$type];

            if (! is_array($entries)) {
                sscAbortGuard("'{$type}' da conexão não é uma configuração válida.");
            }

            // Lista EXATAMENTE como o ConnectionFactory::getReadWriteConfig: isset($entries[0]) — lista ESPARSA
            // (read[0] e read[2]) também é sorteada, qualquer valor dela pode ser o destino. Todas as alternativas
            // são validadas, não só a herdada.
            foreach (isset($entries[0]) ? $entries : [$entries] as $i => $entry) {
                if (! is_array($entry)) {
                    sscAbortGuard("'{$type}[{$i}]' da conexão não é uma configuração válida.");
                }

                $targets["{$type}[{$i}]"] = array_merge($base, $entry);
            }
        }

        return $targets;
    }

    function sscAbortGuard(string $detail): never
    {
        echo "sandbox-clean:abortado: só roda em APP_ENV local/testing, com TODO destino da conexão EFETIVA (DB_URL e suas opções, host, read[], write[]) em host local e banco da lista E2E_ALLOWED_DATABASES — {$detail}\n";

        exit(1);
    }

    /** @param array<string, array<string, mixed>> $targets */
    function sscAssertLocalTargets(array $targets): void
    {
        $allowedDb = array_filter(array_map('trim', explode(',', (string) env('E2E_ALLOWED_DATABASES', 'easyeye,easyeye_test,easyeye_e2e'))));

        foreach ($targets as $label => $target) {
            $database = (string) ($target['database'] ?? '');
            $hosts    = array_values(array_filter(
                array_map(fn ($host) => mb_strtolower(trim((string) $host)), Arr::flatten(Arr::wrap($target['host'] ?? []))),
                fn (string $host) => $host !== '',
            ));

            if ($hosts === [] || array_diff($hosts, ['127.0.0.1', 'localhost', '::1']) !== [] || ! in_array($database, $allowedDb, true)) {
                sscAbortGuard("destino '{$label}': hosts=" . implode('|', $hosts) . " banco={$database} (permitidos: [" . implode(', ', $allowedDb) . ']).');
            }
        }
    }

    /** APP_ENV local/testing e TODO destino de banco local e da lista; aborta (exit 1) antes de tocar em qualquer coisa. */
    function sscGuard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            sscAbortGuard('APP_ENV=' . app()->environment() . '.');
        }

        // 1º os destinos da configuração (sem abrir a conexão): um read/write esparso estraga a criação da conexão e o
        // erro tem de ser o do destino fora da lista, não o do framework.
        sscAssertLocalTargets(sscDatabaseTargets());

        try {
            $live = (array) DB::connection(DB::getDefaultConnection())->getConfig();
        } catch (Throwable $e) {
            sscAbortGuard('não foi possível resolver a conexão viva (' . class_basename($e) . ').');
        }

        sscAssertLocalTargets(['conexão viva' => Arr::except($live, ['read', 'write'])]);
    }
}

(function (): void {
    $emailLike = 'cy-sandbox-%@example.test';
    $emailRe   = '/^cy-sandbox-[a-z0-9._-]+@example\.test$/i';
    $nameLike  = 'Clínica Sandbox %';
    $dryRun    = (string) getenv('SANDBOX_CLEAN_DRY_RUN') === '1';
    $sweep     = (string) getenv('SANDBOX_CLEAN_ALL') === '1';
    $statePath = trim((string) getenv('SANDBOX_CLEAN_STATE'));
    $stateDir  = base_path('e2e/cypress/.sandbox-run');

    $abort = function (string $reason): never {
        echo "sandbox-clean:abortado: {$reason}\n";

        exit(1);
    };

    // ── Guardas: ambiente, banco EFETIVO e só DEPOIS a credencial (que lê banco/cache) ────────────────────────────────
    sscGuard();

    if (Artisan::call('billing:gateway-environment', ['gateway' => 'asaas']) !== 0) {
        $abort('a credencial efetiva do Asaas não é a do sandbox (billing:gateway-environment asaas).');
    }

    $connection = DB::connection();
    echo 'Banco: ' . $connection->getName() . '/' . $connection->getDatabaseName() . ($dryRun ? ' (DRY RUN: nada será apagado)' : '') . "\n";

    // ── Modo: estado da rodada (padrão) × varredura (opt-in) ─────────────────────────────────────────────────────────
    $emails    = null; // lista (minúsculas) do estado da rodada; null = varredura
    $startedAt = null;
    $realState = null;

    if ($sweep && $statePath !== '') {
        $abort('SANDBOX_CLEAN_ALL=1 e SANDBOX_CLEAN_STATE juntos: escolha um modo.');
    }

    if ($sweep) {
        echo "Modo: VARREDURA (SANDBOX_CLEAN_ALL=1) — todo usuário {$emailLike}\n";
    } else {
        if ($statePath === '') {
            $pending = is_dir($stateDir) ? array_map('basename', glob($stateDir . '/*.json') ?: []) : [];

            $abort('sem SANDBOX_CLEAN_STATE (arquivo de estado da rodada) e sem SANDBOX_CLEAN_ALL=1: nada foi apagado.'
                . ($pending !== [] ? ' Estados pendentes em e2e/cypress/.sandbox-run: ' . implode(', ', $pending) . '.' : ' Não há estado pendente.'));
        }

        $realDir   = is_dir($stateDir) ? realpath($stateDir) : false;
        $candidate = is_file($statePath) ? $statePath : (is_file(base_path($statePath)) ? base_path($statePath) : null);
        $realState = $candidate !== null ? realpath($candidate) : false;

        if ($realDir === false || $realState === false || ! str_starts_with($realState, $realDir . DIRECTORY_SEPARATOR) || ! str_ends_with($realState, '.json')) {
            $abort('SANDBOX_CLEAN_STATE precisa ser um arquivo .json existente dentro de e2e/cypress/.sandbox-run.');
        }

        $state = json_decode((string) file_get_contents($realState), true);

        if (! is_array($state) || ! is_array($state['emails'] ?? null) || ! is_string($state['startedAt'] ?? null)) {
            $abort('arquivo de estado inválido (esperado {startedAt, emails[]}).');
        }

        try {
            $startedAt = CarbonImmutable::parse($state['startedAt']);
        } catch (Throwable) {
            $abort('startedAt do estado não é uma data válida.');
        }

        if (count($state['emails']) > 500) {
            $abort('estado com e-mails demais (> 500).');
        }

        foreach ($state['emails'] as $email) {
            if (! is_string($email) || preg_match($emailRe, $email) !== 1) {
                $abort('o estado tem um e-mail fora do padrão cy-sandbox-*@example.test.');
            }
        }

        $emails = array_values(array_unique(array_map('mb_strtolower', $state['emails'])));
        echo 'Modo: RODADA ' . ($state['nonce'] ?? basename($realState, '.json')) . ' — ' . count($emails) . " e-mail(s) registrados, iniciada em {$startedAt->toIso8601String()}\n";
    }

    // Usuários do alvo, relidos do banco a cada chamada. $ids restringe; $lock = FOR UPDATE.
    $selectUsers = function (?array $ids = null, bool $lock = false) use ($emailLike, $emails, $startedAt): Collection {
        $query = DB::table('users')->where('email', 'like', $emailLike);

        if ($emails !== null) {
            $query->whereIn(DB::raw('lower(email)'), $emails);
        }

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $query->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['id', 'email', 'created_at'])
            // Rodada: só conta criada DEPOIS do início dela (folga de 10 min para relógios/fusos).
            ->filter(fn ($user): bool => $startedAt === null || CarbonImmutable::parse((string) $user->created_at, config('app.timezone'))->greaterThanOrEqualTo($startedAt->subMinutes(10)))
            ->keyBy('id');
    };

    // O plano: quais clínicas e usuários entram, dado o conjunto de usuários. Sem efeitos; as travas, quando preciso, o
    // chamador já tomou (a transação final).
    $plan = function (Collection $users) use ($nameLike): array {
        $links     = DB::table('entity_users')->whereIn('user_id', $users->keys())->get(['entity_id', 'user_id']);
        $entityIds = $links->pluck('entity_id')->unique()->values();
        $entities  = DB::table('entities')->whereIn('id', $entityIds)->get(['id', 'name'])->keyBy('id');
        $cleanable = []; // id => name
        $ignored   = [];

        foreach ($entityIds as $entityId) {
            $entity = $entities->get($entityId);

            if ($entity === null) {
                continue; // vínculo sem clínica: some junto com o usuário (cascade)
            }

            $outsiders = DB::table('entity_users')->where('entity_id', $entityId)->whereNotIn('user_id', $users->keys())->count();

            if ($outsiders > 0) {
                $ignored[] = "clínica {$entityId} ({$entity->name}): tem {$outsiders} usuário(s) fora do conjunto da limpeza";

                continue;
            }

            if (! DB::table('entities')->where('id', $entityId)->where('name', 'ilike', $nameLike)->exists()) {
                $ignored[] = "clínica {$entityId} ({$entity->name}): o nome não começa com 'Clínica Sandbox '";

                continue;
            }

            $cleanable[(string) $entityId] = (string) $entity->name;
        }

        // Usuário só sai se todas as clínicas dele entram (ou se ele não tem nenhuma).
        $userIds = $users->keys()->filter(fn ($userId): bool => $links->where('user_id', $userId)->pluck('entity_id')
            ->every(fn ($entityId) => array_key_exists((string) $entityId, $cleanable) || ! DB::table('entities')->where('id', $entityId)->exists()))->values();

        foreach ($users->keys()->diff($userIds) as $userId) {
            $ignored[] = "usuário {$users[$userId]->email}: clínica dele não entrou na limpeza";
        }

        return ['entities' => $cleanable, 'users' => $userIds, 'ignored' => $ignored];
    };

    $finish = function (bool $failed, int $entities, int $users, int $customers, array $ignored) use ($realState, $dryRun): void {
        foreach ($ignored as $line) {
            echo "IGNORADO: {$line}\n";
        }

        // Rodada concluída sem pendência: o estado cumpriu o papel. Com pendência fica (a próxima execução retoma).
        if ($realState !== null && ! $failed && ! $dryRun && is_file($realState)) {
            @unlink($realState);
        }

        echo 'sandbox-clean:' . ($failed ? 'parcial' : 'ok') . ":{$entities}:{$users}:{$customers};\n";

        if ($failed) {
            exit(2);
        }
    };

    $users = $selectUsers();

    if ($users->isEmpty()) {
        echo "nada a limpar\n";
        $finish(false, 0, 0, 0, []);

        return;
    }

    // ── Asaas primeiro (por clínica): clientes, assinaturas e sessões do Checkout hospedado ────────────────────────────
    $gateway = app(GatewayRegistry::class)->get('asaas');
    $request = new ReflectionMethod($gateway, 'request');
    $request->setAccessible(true);
    $call = fn (string $verb, string $endpoint, array $payload = [], array $replace = []): Response => $request->invoke($gateway, $verb, $endpoint, $payload, $replace);
    $why  = fn (Response $r): string => 'HTTP ' . $r->status() . collect((array) $r->json('errors'))
        ->map(fn ($e) => is_array($e) ? ' ' . ($e['code'] ?? '?') . ': ' . ($e['description'] ?? '') : '')
        ->implode(';');

    // Lista PAGINADA e VALIDADA: HTTP 2xx, JSON objeto, `data` lista de objetos e `hasMore` booleano — qualquer desvio é
    // incerteza (exceção), nunca "coleção vazia".
    $listAll = function (string $endpoint, array $query) use ($call, $why): array {
        $rows   = [];
        $offset = 0;

        for ($page = 0; $page < 20; $page++) {
            $response = $call('GET', $endpoint, [...$query, 'limit' => 100, 'offset' => $offset]);
            $json     = $response->json();

            if (! $response->successful() || ! is_array($json) || ! is_array($json['data'] ?? null) || ! is_bool($json['hasMore'] ?? null)) {
                throw new RuntimeException("lista de {$endpoint} inválida ({$why($response)})");
            }

            foreach ($json['data'] as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException("lista de {$endpoint} com item que não é objeto");
                }

                $rows[] = $row;
            }

            if ($json['hasMore'] === false) {
                return $rows;
            }

            $offset += max(1, count($json['data']));
        }

        throw new RuntimeException("lista de {$endpoint} com páginas demais");
    };

    // DELETE + confirmação (deleted:true ou 404 numa releitura; o Asaas confirma com atraso, até 5 leituras).
    $remove = function (string $deleteEndpoint, string $showEndpoint, string $id, string $label) use ($call, $why): void {
        $deleted = $call('DELETE', $deleteEndpoint, [], ['id' => $id]);

        if ($deleted->status() !== 404 && ! $deleted->successful()) {
            throw new RuntimeException("não removeu {$label} {$id} ({$why($deleted)})");
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $show = $call('GET', $showEndpoint, [], ['id' => $id]);

            if ($show->status() === 404 || ($show->successful() && $show->json('deleted') === true)) {
                return;
            }

            if ($attempt < 5) {
                sleep(1);
            }
        }

        throw new RuntimeException("remoção de {$label} {$id} não confirmada");
    };

    // TRAVA ANTES do Asaas e segura até o commit: clínicas (FOR UPDATE, ordenadas por id) → usuários. Um INSERT em
    // entity_users toma KEY SHARE na clínica (e um vínculo do usuário de teste a outra clínica, na linha do usuário) e
    // ESPERA esta transação — nenhum vínculo novo nasce entre a elegibilidade e os DELETEs. A elegibilidade é decidida
    // aqui, já sob a trava, e só as clínicas elegíveis chegam a ser tocadas no Asaas. É script de teste em banco de dev:
    // a transação aberta durante as chamadas remotas (segundos) é aceitável. Qualquer exceção fora do tratamento por
    // clínica encerra o script com a transação aberta, e o banco a desfaz ao fechar a conexão (nada local é apagado; o
    // Asaas é idempotente e a próxima execução retoma).
    DB::beginTransaction();

    $linkedEntityIds = DB::table('entity_users')->whereIn('user_id', $users->keys())->pluck('entity_id')->unique()->sort()->values()->all();
    $lockedFirst     = $linkedEntityIds === [] ? [] : DB::table('entities')->whereIn('id', $linkedEntityIds)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (string) $id)->all();
    $firstIds        = $users->keys()->merge(DB::table('entity_users')->whereIn('entity_id', $lockedFirst)->pluck('user_id'))->unique()->values()->all();
    $first           = $plan($selectUsers($firstIds, lock: true));
    $cleanable       = $first['entities'];
    $ignored         = $first['ignored'];

    $asaasCustomers     = 0;
    $asaasSubscriptions = 0;
    $failed             = false;
    $asaasDone          = []; // id => nome: clínicas cujo lado Asaas está limpo (ou nada havia)

    foreach (array_keys($cleanable) as $entityId) {
        try {
            // 1. Clientes por externalReference (nunca confia só no filtro) e as assinaturas deles.
            $customers = collect($listAll('customers', ['externalReference' => $entityId]))
                ->filter(fn ($row) => ($row['externalReference'] ?? null) === $entityId && ($row['deleted'] ?? false) !== true);
            $ownCustomerIds = $customers->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();

            foreach ($customers as $customer) {
                $customerId = (string) ($customer['id'] ?? '');

                if ($customerId === '') {
                    throw new RuntimeException('cliente sem id na lista');
                }

                foreach ($listAll('subscriptions', ['customer' => $customerId]) as $subscription) {
                    if (($subscription['customer'] ?? null) !== $customerId || ($subscription['deleted'] ?? false) === true || blank($subscription['id'] ?? null)) {
                        continue;
                    }

                    if ($dryRun) {
                        echo "  [dry-run] Asaas: cancelaria a assinatura {$subscription['id']} do cliente {$customerId}\n";
                        $asaasSubscriptions++;

                        continue;
                    }

                    $remove('subscription_cancel', 'subscription_show', (string) $subscription['id'], 'a assinatura');
                    $asaasSubscriptions++;
                }

                if ($dryRun) {
                    echo "  [dry-run] Asaas: apagaria o cliente {$customerId} (clínica {$entityId})\n";
                    $asaasCustomers++;

                    continue;
                }

                $remove('customer_show', 'customer_show', $customerId, 'o cliente');
                $asaasCustomers++;
            }

            // 2. Sessões do Checkout hospedado (o fallback abre o checkout SEM cliente: nada acima as alcança).
            foreach (DB::table('billing_hosted_checkouts')->where('entity_id', $entityId)->get(['external_checkout_id', 'external_subscription_id']) as $checkout) {
                $sessionId = (string) $checkout->external_checkout_id;

                if ($dryRun) {
                    echo "  [dry-run] Asaas: cancelaria a sessão do checkout {$sessionId}\n";

                    continue;
                }

                $status = $gateway->cancelHostedCheckoutStatus($sessionId);

                if (in_array($status, ['canceled', 'expired'], true)) {
                    continue;
                }

                // Paga: a sessão não cancela; a recorrência que nasceu dela precisa estar registrada para ser desfeita.
                if ($status !== 'paid' || blank($checkout->external_subscription_id)) {
                    throw new RuntimeException("sessão do checkout {$sessionId} em estado '{$status}'" . ($status === 'paid' ? ' sem a recorrência registrada' : '') . ' — incerta');
                }

                $born = $call('GET', 'subscription_show', [], ['id' => (string) $checkout->external_subscription_id]);

                if ($born->status() === 404 || ($born->successful() && $born->json('deleted') === true)) {
                    continue;
                }

                if (! $born->successful() || ! is_array($born->json())) {
                    throw new RuntimeException("recorrência {$checkout->external_subscription_id} do checkout não consultável ({$why($born)})");
                }

                $bornCustomer = (string) $born->json('customer');
                $remove('subscription_cancel', 'subscription_show', (string) $checkout->external_subscription_id, 'a recorrência do checkout');
                $asaasSubscriptions++;

                // O cliente criado pela página do checkout (sem a nossa referência) é melhor-esforço: só reporta se falhar.
                if ($bornCustomer !== '' && ! in_array($bornCustomer, $ownCustomerIds, true)) {
                    try {
                        $bornShow = $call('GET', 'customer_show', [], ['id' => $bornCustomer]);

                        if ($bornShow->successful() && blank($bornShow->json('externalReference')) && $bornShow->json('deleted') !== true) {
                            $remove('customer_show', 'customer_show', $bornCustomer, 'o cliente nascido no checkout');
                            $asaasCustomers++;
                        }
                    } catch (Throwable $e) {
                        $ignored[] = "clínica {$entityId}: cliente {$bornCustomer} nascido no checkout não foi apagado ({$e->getMessage()}) — apague no painel do sandbox";
                    }
                }
            }

            $asaasDone[$entityId] = $cleanable[$entityId];
        } catch (Throwable $e) {
            $ignored[] = "clínica {$entityId}: Asaas — {$e->getMessage()}";
            $failed    = true;
        }
    }

    // ── Banco: trava, RELÊ e reconfere os predicados dentro da transação, depois apaga ────────────────────────────────
    $final = ['entities' => [], 'users' => collect(), 'counts' => ['entity_users' => 0, 'subscriptions' => 0, 'invoices' => 0, 'billing_logs' => 0]];

    $apply = function () use (&$final, $asaasDone, $first, $selectUsers, $plan, $dryRun, &$ignored, &$failed): void {
        // Clínicas primeiro (um INSERT em entity_users toma KEY SHARE na clínica e espera esta trava), depois usuários.
        $lockedEntities = $asaasDone === [] ? [] : DB::table('entities')->whereIn('id', array_keys($asaasDone))->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (string) $id)->all();

        // Candidatos: os do plano inicial + quem se vinculou a essas clínicas desde então (descartados se não casam
        // com os predicados — um usuário real vinculado no intervalo faz a clínica sair do plano).
        $candidateIds = $first['users']->merge(DB::table('entity_users')->whereIn('entity_id', $lockedEntities)->pluck('user_id'))->unique()->values()->all();
        $fresh        = $candidateIds === [] ? collect() : $selectUsers($candidateIds, lock: true);
        $now          = $plan($fresh);
        $entities     = array_intersect_key($now['entities'], array_flip($lockedEntities));

        foreach (array_diff_key($asaasDone, $entities) as $entityId => $name) {
            $ignored[] = "clínica {$entityId} ({$name}): mudou durante a limpeza (vínculo, nome ou usuários) — o Asaas já foi limpo, a clínica FICOU no banco";
            $failed    = true;
        }

        // Usuário só sai se todas as clínicas dele (relidas agora) estão entre as que saem.
        $links   = DB::table('entity_users')->whereIn('user_id', $fresh->keys())->get(['entity_id', 'user_id']);
        $userIds = $fresh->keys()->filter(fn ($userId) => $links->where('user_id', $userId)->pluck('entity_id')
            ->every(fn ($entityId) => array_key_exists((string) $entityId, $entities) || ! DB::table('entities')->where('id', $entityId)->exists()))->values();

        foreach ($first['users']->diff($userIds) as $userId) {
            $line = 'usuário ' . ($fresh[$userId]->email ?? $userId) . ': clínica dele não entrou na limpeza';

            if (! in_array($line, $ignored, true)) {
                $ignored[] = $line;
            }
        }

        $ids = array_keys($entities);

        $final = [
            'entities' => $entities,
            'users'    => $userIds->map(fn ($id) => ['id' => $id, 'email' => $fresh[$id]->email ?? $id]),
            'counts'   => [
                'entity_users'  => $ids === [] ? 0 : DB::table('entity_users')->whereIn('entity_id', $ids)->count(),
                'subscriptions' => $ids === [] ? 0 : DB::table('subscriptions')->whereIn('entity_id', $ids)->count(),
                'invoices'      => $ids === [] ? 0 : DB::table('invoices')->whereIn('entity_id', $ids)->count(),
                'billing_logs'  => $ids === [] ? 0 : DB::table('billing_logs')->whereIn('entity_id', $ids)->count(),
            ],
        ];

        if ($dryRun) {
            return;
        }

        if ($ids !== []) {
            $entityUserIds = DB::table('entity_users')->whereIn('entity_id', $ids)->pluck('id');
            DB::table('doctors')->whereIn('entity_user_id', $entityUserIds)->delete();
            // Linhas sem FK em cascata que o fluxo pode gerar para estas clínicas.
            DB::table('notices')->whereIn('entity_id', $ids)->delete();
            DB::table('schedule_events')->whereIn('entity_id', $ids)->delete();
            DB::table('waiting_list')->whereIn('entity_id', $ids)->delete();
            DB::table('billing_logs')->whereIn('entity_id', $ids)->delete();
            DB::table('entities')->whereIn('id', $ids)->delete();
        }

        if ($userIds->isNotEmpty()) {
            DB::table('ai_runs')->whereIn('requested_by', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        }
    };

    // Revalidação final (sob as mesmas travas) e DELETEs; o commit solta tudo.
    $apply();
    DB::commit();

    // ── Relatório ────────────────────────────────────────────────────────────────────────────────────────────────────
    $verb   = $dryRun ? 'Apagaria' : 'Apagado';
    $counts = $final['counts'];

    echo "{$verb} no Asaas sandbox: clientes {$asaasCustomers}, assinaturas {$asaasSubscriptions}\n";
    echo "{$verb} no banco: clínicas " . count($final['entities']) . " (vínculos {$counts['entity_users']}, assinaturas {$counts['subscriptions']}, faturas {$counts['invoices']}, billing_logs {$counts['billing_logs']}), usuários {$final['users']->count()}\n";

    foreach ($final['entities'] as $entityId => $name) {
        echo "  clínica {$entityId} ({$name})\n";
    }

    foreach ($final['users'] as $user) {
        echo "  usuário {$user['email']}\n";
    }

    $finish($failed, count($final['entities']), $final['users']->count(), $asaasCustomers, $ignored);
})();
