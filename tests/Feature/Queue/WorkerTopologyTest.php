<?php

declare(strict_types=1);

use Tests\Support\QueueTopology;

/**
 * F1 "Filas com prioridade": travas ESTÁTICAS da topologia de workers (Supervisor, entrypoint, crontab do ambiente de
 * teste e config/queue.php), sem Redis nem worker real — rodam na CI. O que precisa de worker/Redis de verdade fica em
 * tests/Integration/QueueWorkerSmokeTest.php e QueueRedisReservationTest.php. Mudar uma fila no .env sem mudar o
 * --queue do worker reprova aqui de propósito. Runbook: docs/internal/runbooks/filas-workers.md.
 */
it('o Supervisor tem os programas fast-critical, fast-tiss e bulk, 3 processos, e nenhum usa --connection (a conexão é posicional)', function () {
    $conf     = (string) file_get_contents(base_path(QueueTopology::SUPERVISOR_CONF));
    $programs = QueueTopology::programs();

    expect(array_keys($programs))->toBe(['easyeye-worker-fast-critical', 'easyeye-worker-fast-tiss', 'easyeye-worker-bulk'])
        ->and(array_sum(array_column($programs, 'numprocs')))->toBe(3)
        ->and($conf)->not->toContain('--connection');
});

it('retry_after de cada conexão é maior que o maior $timeout declarado entre os jobs que ela serve (EE-09)', function (string $connection, string $scope, int $expected) {
    $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

    // Acima do maior job do escopo (senão outro worker reentrega o MESMO job no meio)...
    expect($retryAfter)->toBeGreaterThan(QueueTopology::maxDeclaredJobTimeout($scope))
        // ...e justo, não folgado: o `redis` voltar a 960 s devolveria um webhook de pagamento de worker morto em 16 min.
        ->and($retryAfter)->toBe($expected);
})->with([
    'database (todos os jobs)' => ['database', 'all', 960],
    'redis (fast)'             => ['redis', 'fast', 240],
    'redis-bulk (bulk)'        => ['redis-bulk', 'bulk', 960],
]);

it('o job de IA (timeout calculado no construtor) cabe no retry_after da conexão fast', function () {
    expect((int) config('ai.jobs.timeout_seconds', 180))->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('cada programa do Supervisor cobre o maior job que serve: --timeout, stopwaitsecs, numprocs, tries e memória', function (string $program, string $connection, string $scope, string $connectionToken, int $tries, int $memory) {
    $worker     = QueueTopology::programs()[$program];
    $maxTimeout = QueueTopology::maxDeclaredJobTimeout($scope);
    $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

    // --timeout vale para o job SEM $timeout próprio: cobre o maior declarado e fica abaixo do retry_after
    // (senão outro worker pega o MESMO job enquanto o 1º, legítimo, ainda roda).
    expect($worker['connection'])->toBe($connectionToken)
        ->and($worker['timeout'])->toBeGreaterThanOrEqual($maxTimeout)
        ->and($worker['timeout'])->toBeLessThan($retryAfter)
        // stopwaitsecs: o SIGTERM de um deploy espera o job corrente terminar antes do SIGKILL.
        ->and($worker['stopwaitsecs'])->toBeGreaterThan($maxTimeout)
        ->and($worker['numprocs'])->toBe(1)
        ->and($worker['tries'])->toBe($tries)
        ->and($worker['memory'])->toBe($memory);
})->with([
    'fast-critical' => ['easyeye-worker-fast-critical', 'redis', 'fast', '%(ENV_SUPERVISOR_QUEUE_CONNECTION)s', 3, 256],
    'fast-tiss'     => ['easyeye-worker-fast-tiss', 'redis', 'fast', '%(ENV_SUPERVISOR_QUEUE_CONNECTION)s', 3, 256],
    // A conexão do bulk é a VARIÁVEL do entrypoint (redis-bulk com Redis, database sem): nunca `redis-bulk` literal.
    'bulk' => ['easyeye-worker-bulk', 'redis-bulk', 'bulk', '%(ENV_SUPERVISOR_BULK_CONNECTION)s', 1, 512],
]);

it('TODO programa fast consome TODAS as filas configuradas do fast (nenhum job fica sem consumidor se um deles cair)', function () {
    $configured = QueueTopology::configuredFastQueues();

    foreach (QueueTopology::fastPrograms() as $name => $program) {
        expect(array_values(array_diff($configured, $program['queues'])))->toBe([], "{$name}: fila configurada que ele não lê")
            ->and($program['queues'])->not->toContain((string) config('queue.bulk_queue'));
    }
});

it('a precedência existe: um fast lê critical primeiro e OUTRO lê as 3 filas TISS antes de default e de critical (ordem de leitura; não prova ausência de starvation)', function () {
    $fast = array_map(fn (array $program): array => $program['queues'], QueueTopology::fastPrograms());
    $tiss = [config('tiss.queues.xml'), config('tiss.queues.send'), config('tiss.queues.returns')];

    $criticalFirst = array_filter($fast, fn (array $queues): bool => ($queues[0] ?? null) === 'critical');
    $tissFirst     = array_filter($fast, function (array $queues) use ($tiss): bool {
        $positions = array_map(fn (string $queue) => array_search($queue, $queues, true), $tiss);

        return ! in_array(false, $positions, true)
            && max($positions) < (int) array_search('default', $queues, true)
            && max($positions) < (int) array_search('critical', $queues, true);
    });

    expect($criticalFirst)->not->toBe([], 'nenhum fast lê critical primeiro')
        ->and($tissFirst)->not->toBe([], 'nenhum fast lê as 3 filas TISS antes de default e critical: default cheia deixa o TISS sem precedência')
        // São programas diferentes: o mesmo programa não pode cumprir os dois papéis.
        ->and(array_keys($criticalFirst))->not->toBe(array_keys($tissFirst));
});

it('o bulk consome só a fila bulk', function () {
    expect(QueueTopology::programs()['easyeye-worker-bulk']['queues'])->toBe([(string) config('queue.bulk_queue')]);
});

it('redis-bulk consome a MESMA fila Redis em que os jobs bulk são despachados (mesmo Redis DB/prefixo), sem long poll', function () {
    expect(config('queue.connections.redis-bulk.driver'))->toBe('redis')
        ->and(config('queue.connections.redis-bulk.connection'))->toBe(config('queue.connections.redis.connection'))
        ->and(config('queue.connections.redis-bulk.block_for'))->toBeNull()
        ->and(config('queue.connections.redis.block_for'))->toBeNull();
});

it('o entrypoint do Docker faz o bulk seguir a conexão efetiva: redis → redis-bulk, database (ou ausente/vazia) → database, sem aviso', function (?string $queueConnection, string $expected) {
    expect(QueueTopology::entrypointBulkConnection($queueConnection))->toBe(['value' => $expected, 'stderr' => '']);
})->with([
    'redis'            => ['redis', 'redis-bulk'],
    'database'         => ['database', 'database'],
    'variável ausente' => [null, 'database'],
    'variável vazia'   => ['', 'database'],
]);

it('o entrypoint avisa no stderr (sem morrer) quando a conexão não é suportada pelos workers, e exporta o valor mesmo assim', function () {
    $result = QueueTopology::entrypointBulkConnection('sync');

    expect($result['stderr'])->toContain("QUEUE_CONNECTION='sync' não suportada pelos workers (use redis ou database)")
        ->and($result['value'])->toBe('sync');
});

it('o crontab do ambiente de teste traz as 3 linhas padrão: fast-critical (critical primeiro), fast-tiss (TISS primeiro) e bulk, cada uma com flock e log próprios', function () {
    $doc = (string) file_get_contents(base_path('docs/internal/infra/reverb-ambiente-teste.md'));

    preg_match_all('/^\* \* \* \* \* .*flock -n (\S+) .*artisan queue:work (\S+) --queue=(\S+) .*>> (\S+) 2>&1$/m', $doc, $lines, PREG_SET_ORDER);

    $cron = [];

    foreach ($lines as [, $lock, $connection, $queues, $log]) {
        $cron[] = ['lock' => $lock, 'connection' => $connection, 'queues' => explode(',', $queues), 'log' => $log];
    }

    $configured = QueueTopology::configuredFastQueues();
    $fast       = array_values(array_filter($cron, fn (array $line): bool => $line['connection'] === 'redis'));
    $bulk       = array_values(array_filter($cron, fn (array $line): bool => $line['connection'] === 'redis-bulk'));

    expect($doc)->not->toContain('queue:work --connection')
        ->and($fast)->toHaveCount(2)
        ->and($bulk)->toHaveCount(1)
        ->and($bulk[0]['queues'])->toBe([(string) config('queue.bulk_queue')])
        // cada linha do fast lê todas as filas configuradas do fast
        ->and(array_values(array_diff($configured, $fast[0]['queues'])))->toBe([])
        ->and(array_values(array_diff($configured, $fast[1]['queues'])))->toBe([])
        // linha 1 = critical primeiro; linha 2 = as 3 TISS na frente, depois default e critical
        ->and($fast[0]['queues'][0])->toBe('critical')
        ->and(array_slice($fast[1]['queues'], 0, 3))->toEqualCanonicalizing(['tiss-xml', 'tiss-send', 'tiss-returns'])
        ->and(array_slice($fast[1]['queues'], 3))->toBe(['default', 'critical'])
        // um flock e um log por linha (duas linhas com o mesmo lock não rodariam juntas)
        ->and(array_unique(array_column($cron, 'lock')))->toHaveCount(3)
        ->and(array_unique(array_column($cron, 'log')))->toHaveCount(3);
});

it('DOCKER.md, onboarding, infra do ambiente de teste e runbook usam os mesmos nomes de programa do Supervisor (e a mesma contagem de processos)', function () {
    $names = array_keys(QueueTopology::programs());

    foreach (['DOCKER.md', 'docs/onboarding.md', 'docs/internal/infra/reverb-ambiente-teste.md', 'docs/internal/runbooks/filas-workers.md'] as $doc) {
        $text = (string) file_get_contents(base_path($doc));

        preg_match_all('/easyeye-worker-[a-z]+(?:-[a-z]+)?/', $text, $mentioned);

        // nenhum nome de programa que não exista no conf (ex.: o antigo fast-default)
        expect(array_values(array_diff(array_unique($mentioned[0]), $names)))->toBe([], "{$doc}: programa inexistente no Supervisor");
        // nenhum doc volta a falar do desenho antigo
        expect($text)->not->toContain('fast-default')->not->toContain('easyeye-fast.log');
    }

    // contagem de processos: 3 no Docker (Supervisor) e 3 linhas de cron no ambiente de teste
    expect(array_sum(array_column(QueueTopology::programs(), 'numprocs')))->toBe(3)
        ->and((string) file_get_contents(base_path('DOCKER.md')))->toContain('**3 processos**')
        ->and((string) file_get_contents(base_path('docs/internal/infra/reverb-ambiente-teste.md')))->toContain('3 linhas de cron, 3 processos');
});

it('todo job de App\\Jobs nomeia a sua fila: nenhum ficou na `default` por acaso, fora do mapa de prioridades', function () {
    // Os jobs TISS ficam de fora: a fila deles é escolhida pelo service (config/tiss.php "queues"). Notificações e
    // listeners `ShouldQueue` seguem na `default` dos workers fast e estão no mapa do plano (§3.6).
    $semFila = array_values(array_filter(
        QueueTopology::jobClasses(),
        fn (string $class): bool => str_starts_with($class, 'App\\Jobs\\')
            && ! str_contains((string) file_get_contents((string) (new ReflectionClass($class))->getFileName()), 'onQueue('),
    ));

    expect($semFila)->toBe([]);
});
