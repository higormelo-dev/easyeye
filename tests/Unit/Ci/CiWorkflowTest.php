<?php

declare(strict_types=1);

/**
 * Contrato do workflow (.github/workflows/integrator-contracts.yml) depois de dividido em jobs.
 *
 * O que estes testes protegem, em ordem do que custa mais caro quando quebra em silêncio:
 *  - banco: cada job de Pest tem o PRÓPRIO Postgres/Redis (fatias que dividem banco colidem em migrate:fresh);
 *  - OMP_THREAD_LIMIT=1 no ambiente INICIAL do Pest (sem ele o fork de IntegratorP2ConcurrencyTest trava no libgomp);
 *  - o check exigido `contracts` continua existindo e só passa se tudo passou;
 *  - o teto de tempo e a matriz vêm do seletor, nunca do evento;
 *  - fixtures HTTP validadas só no job que as gera; JUnit de cada fatia enviado mesmo em falha;
 *  - nada deixa uma falha passar por verde (fatia vazia, `continue-on-error`, `|| true`) e nada de `${{ }}` em `run:`;
 *  - privilégio mínimo: permissões, credenciais do checkout e retenção dos artefatos.
 */

use Symfony\Component\Process\Process;
use Tests\Unit\Ci\Support\CiHarness;

/** O ambiente que o passo enxerga: workflow → job → passo (o mais interno ganha). */
function ciwEnv(array $workflow, array $job, array $step = []): array
{
    return [...($workflow['env'] ?? []), ...($job['env'] ?? []), ...($step['env'] ?? [])];
}

/** Os passos que rodam o Pest. */
function ciwPestSteps(array $job): array
{
    return array_values(array_filter($job['steps'], fn (array $step) => str_contains((string) ($step['run'] ?? ''), 'vendor/bin/pest')));
}

/** Os jobs que rodam o Pest, por id. */
function ciwPestJobs(array $workflow): array
{
    return array_filter($workflow['jobs'], fn (array $job) => ciwPestSteps($job) !== []);
}

it('os jobs são os esperados e o php-tests é o único que roda o Pest', function () {
    $workflow = CiHarness::workflow();

    expect(array_keys($workflow['jobs']))->toBe(['select', 'assets', 'frontend-tests', 'quality', 'php-tests', 'contracts'])
        ->and(array_keys(ciwPestJobs($workflow)))->toBe(['php-tests']);
});

it('cada job de Pest tem o PRÓPRIO Postgres e Redis, e as variáveis do app apontam para eles', function () {
    $workflow = CiHarness::workflow();

    foreach (ciwPestJobs($workflow) as $id => $job) {
        $env      = ciwEnv($workflow, $job);
        $postgres = $job['services']['postgres'] ?? null;
        $redis    = $job['services']['redis'] ?? null;

        expect([$id => $postgres !== null && $redis !== null])->toBe([$id => true])
            ->and($postgres['image'])->toStartWith('postgres:')
            ->and($postgres['ports'])->toBe(['5432:5432'])
            ->and($postgres['env']['POSTGRES_DB'])->toBe($env['DB_DATABASE'])
            ->and($postgres['env']['POSTGRES_USER'])->toBe($env['DB_USERNAME'])
            ->and($postgres['env']['POSTGRES_PASSWORD'])->toBe($env['DB_PASSWORD'])
            ->and($postgres['options'])->toContain('pg_isready -U postgres -d ' . $env['DB_DATABASE'])
            ->and($env['DB_CONNECTION'])->toBe('pgsql')
            ->and($env['DB_HOST'])->toBe('127.0.0.1')
            ->and((string) $env['DB_PORT'])->toBe('5432')
            ->and($redis['image'])->toStartWith('redis:')
            ->and($redis['ports'])->toBe(['6379:6379'])
            ->and($redis['options'])->toContain('redis-cli ping')
            ->and($env['REDIS_HOST'])->toBe('127.0.0.1')
            ->and((string) $env['REDIS_PORT'])->toBe('6379')
            ->and($env['REDIS_CLIENT'])->toBe('phpredis');
    }

    // Banco é do job: nada de serviço no nível do workflow, e nenhum job sem Pest segura um Postgres à toa.
    expect($workflow)->not->toHaveKey('services');

    $withoutPest = array_diff_key($workflow['jobs'], ciwPestJobs($workflow));

    expect(array_filter(array_map(fn (array $job) => array_key_exists('services', $job), $withoutPest)))->toBe([]);
});

it('a extensão redis está na lista do setup-php só dos jobs que rodam o Pest (o quality não abre conexão Redis)', function () {
    $workflow = CiHarness::workflow();
    $pestJobs = array_keys(ciwPestJobs($workflow));

    foreach (['php-tests', 'quality'] as $id) {
        $setup      = collect($workflow['jobs'][$id]['steps'])->first(fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'shivammathur/setup-php@'));
        $extensions = array_map('trim', explode(',', $setup['with']['extensions']));

        expect($extensions)->toContain('pdo_pgsql', 'pcntl', 'posix', 'gd', 'sodium', 'calendar');

        if (in_array($id, $pestJobs, true)) {
            expect($extensions)->toContain('redis'); // QueueRedisReservationTest fala com um Redis de verdade
        } else {
            expect($extensions)->not->toContain('redis'); // composer + package:discover não precisam; custo à toa
        }
    }
});

it('OMP_THREAD_LIMIT=1 está no ambiente INICIAL de todo job que roda Pest', function () {
    $workflow = CiHarness::workflow();

    foreach (ciwPestJobs($workflow) as $id => $job) {
        foreach (ciwPestSteps($job) as $step) {
            expect([$id => (string) (ciwEnv($workflow, $job, $step)['OMP_THREAD_LIMIT'] ?? '')])->toBe([$id => '1']);
        }

        // Dentro do PHP não adianta (a libgomp já leu o ambiente ao carregar): nada de putenv/ini_set no workflow.
        expect(json_encode($job))->not->toContain('putenv');
    }
});

it('o fuso de Brasília (TZ, APP_TIMEZONE, PGTZ) vale para o Pest e para o Vitest', function () {
    $workflow = CiHarness::workflow();

    foreach (['php-tests', 'frontend-tests'] as $id) {
        $env = ciwEnv($workflow, $workflow['jobs'][$id]);

        expect($env['TZ'])->toBe('America/Sao_Paulo');
    }

    $php = ciwEnv($workflow, $workflow['jobs']['php-tests']);

    expect($php['APP_TIMEZONE'])->toBe('America/Sao_Paulo')
        ->and($php['PGTZ'])->toBe('America/Sao_Paulo');
});

it('o runner tem o poppler-utils (pdftotext) e um .env VAZIO antes de qualquer comando que suba o Laravel', function () {
    $workflow = CiHarness::workflow();

    $touchedBeforeComposer = [];

    foreach (['php-tests', 'quality'] as $id) {
        $runs     = array_map(fn (array $step) => (string) ($step['run'] ?? ''), $workflow['jobs'][$id]['steps']);
        $touch    = array_search('touch .env', $runs, true);
        $composer = array_keys(array_filter($runs, fn (string $run) => str_contains($run, 'composer install')));

        $touchedBeforeComposer[$id] = $touch !== false && count($composer) === 1 && $touch < $composer[0];
    }

    expect($touchedBeforeComposer)->toBe(['php-tests' => true, 'quality' => true]);

    $php  = $workflow['jobs']['php-tests']['steps'];
    $pest = array_key_first(array_filter($php, fn (array $step) => str_contains((string) ($step['run'] ?? ''), 'vendor/bin/pest')));
    $apt  = collect($php)->first(fn (array $step) => str_contains((string) ($step['run'] ?? ''), 'apt-get install'));

    expect($apt['run'])->toContain('poppler-utils')
        ->and($apt['run'])->toContain('ghostscript')
        ->and($apt['run'])->toContain('util-linux')
        ->and(array_search('touch .env', array_map(fn (array $step) => $step['run'] ?? '', $php), true))->toBeLessThan($pest);

    // O .env é vazio de propósito: o .env.example traz defaults que os testes conferem como ausentes. (Só os comandos dos
    // passos: o comentário do workflow cita o arquivo para explicar por que não é copiado.)
    $commands = implode("\n", array_map(fn (array $step) => (string) ($step['run'] ?? ''), array_merge(...array_column($workflow['jobs'], 'steps'))));

    expect($commands)->not->toContain('.env.example');
});

it('as fatias vêm da matriz do seletor: fail-fast desligado e sem modo paralelo do Pest', function () {
    $workflow = CiHarness::workflow();
    $job      = $workflow['jobs']['php-tests'];
    [$pest]   = ciwPestSteps($job);
    $command  = collect(explode("\n", $pest['run']))->first(fn (string $line) => str_contains($line, 'vendor/bin/pest'));

    expect($job['needs'])->toEqualCanonicalizing(['select', 'assets'])
        ->and($job['strategy']['fail-fast'])->toBeFalse()
        ->and($job['strategy']['matrix'])->toBe('${{ fromJSON(needs.select.outputs.matrix) }}')
        ->and($pest['env']['PEST_ARGS'])->toBe('${{ matrix.pest_args }}')
        ->and($pest['env']['PEST_SHARD'])->toBe('${{ matrix.shard }}')
        ->and($pest['run'])->toContain('--shard=')
        ->and($pest['run'])->toContain('--log-junit')
        ->and($command)->toContain('--fail-on-empty-test-suite') // fatia que roda 0 testes sairia verde (exit 0)
        ->and($command)->not->toContain('--parallel')
        ->and($command)->not->toMatch('/\s-p(\s|$)/');
});

/**
 * Executa o passo do Pest de verdade, com um `php` falso no PATH que grava o argv (um argumento por linha) e sai com o
 * código pedido. As expressões do `env:` do passo são resolvidas com a entrada da matriz — o mapeamento do workflow é
 * parte do que se prova.
 *
 * @param array{id: string, pest_args: string, shard: string, fixtures: bool} $entry
 *
 * @return array{exit: int, argv: list<string>, junit: string, dir: string}
 */
function ciwRunPestStep(array $entry, int $phpExit = 0, ?string $pestArgs = null): array
{
    [$step] = ciwPestSteps(CiHarness::workflow()['jobs']['php-tests']);

    $dir = sys_get_temp_dir() . '/ciw-' . bin2hex(random_bytes(6));
    mkdir($dir . '/bin', 0777, true);
    file_put_contents($dir . '/bin/php', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$FAKE_PHP_ARGV\"\nexit \"\${FAKE_PHP_EXIT:-0}\"\n");
    chmod($dir . '/bin/php', 0755);

    $env = [
        'PATH'          => $dir . '/bin:/usr/bin:/bin',
        'FAKE_PHP_ARGV' => $dir . '/argv',
        'FAKE_PHP_EXIT' => (string) $phpExit,
    ];

    foreach ($step['env'] as $name => $expression) {
        $env[$name] = preg_replace_callback(
            '/\$\{\{\s*(matrix\.(\w+)|runner\.temp)\s*\}\}/',
            fn (array $m) => $m[1] === 'runner.temp' ? $dir . '/runner-temp' : (string) $entry[$m[2]],
            (string) $expression,
        );
    }

    if ($pestArgs !== null) {
        $env['PEST_ARGS'] = $pestArgs;
    }

    // O shell padrão do Actions no Linux: `bash --noprofile --norc -eo pipefail`.
    $process = new Process(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $step['run']], $dir, $env);
    $process->run();

    return [
        'exit'  => (int) $process->getExitCode(),
        'argv'  => is_file($dir . '/argv') ? explode("\n", rtrim((string) file_get_contents($dir . '/argv'), "\n")) : [],
        'junit' => $env['JUNIT_DIR'],
        'dir'   => $dir,
    ];
}

it('o passo do Pest, executado, leva --shard só nas entradas fatiadas e sempre --fail-on-empty-test-suite e o JUnit da fatia', function () {
    $entries = [
        ...CiHarness::select('push', '')['matrix']['include'],                         // 6 fatias
        ...CiHarness::selectWithChanged(CiHarness::testFiles(3))['matrix']['include'], // entrada única, sem --shard
    ];

    expect($entries)->toHaveCount(7);

    foreach ($entries as $entry) {
        $ran = ciwRunPestStep($entry);

        $expected = [
            'vendor/bin/pest',
            ...explode(' ', $entry['pest_args']),
            ...($entry['shard'] === '' ? [] : ['--shard=' . $entry['shard']]),
            '--fail-on-empty-test-suite',
            '--compact',
            '--log-junit=' . $ran['junit'] . '/' . $entry['id'] . '.xml',
        ];

        expect([$entry['id'] => $ran['argv']])->toBe([$entry['id'] => $expected])
            ->and($ran['exit'])->toBe(0)
            ->and(is_dir($ran['junit']))->toBeTrue(); // `mkdir -p` do diretório do JUnit
    }

    // As entradas fatiadas levam o --shard; a única não (o inverso rodaria o grupo inteiro em cada job).
    $sharded = array_filter($entries, fn (array $entry) => $entry['shard'] !== '');

    expect($sharded)->toHaveCount(6);

    foreach (ciwRunPestStep($entries[6])['argv'] as $arg) {
        expect($arg)->not->toStartWith('--shard');
    }
});

it('o passo do Pest propaga a falha do Pest (exit != 0) e não deixa o PEST_ARGS virar comando', function () {
    $entry = CiHarness::select('push', '')['matrix']['include'][0];

    expect(ciwRunPestStep($entry, phpExit: 1)['exit'])->toBe(1)
        ->and(ciwRunPestStep($entry, phpExit: 2)['exit'])->toBe(2);

    // Word splitting e glob acontecem, mas nada é reavaliado como código: nenhum marcador é criado.
    $marker = sys_get_temp_dir() . '/ciw-marker-' . bin2hex(random_bytes(6));
    $ran    = ciwRunPestStep($entry, pestArgs: 'tests/A.php $(touch ' . $marker . ') `touch ' . $marker . '`; touch ' . $marker);

    expect(file_exists($marker))->toBeFalse()
        ->and($ran['argv'])->toContain('tests/A.php', '$(touch', '`touch');
});

it('nada deixa uma falha passar por verde: sem continue-on-error em job ou passo, sem `|| true`/`set +e` no Pest', function () {
    $workflow = CiHarness::workflow();

    expect(json_encode($workflow))->not->toContain('continue-on-error');

    foreach (ciwPestSteps($workflow['jobs']['php-tests']) as $step) {
        expect($step['run'])->not->toContain('|| true')
            ->and($step['run'])->not->toContain('|| :')
            ->and($step['run'])->not->toContain('set +e');
    }
});

it('o job php-tests não espera o Vitest nem o estilo (eles correm em paralelo)', function () {
    $needs = CiHarness::workflow()['jobs']['php-tests']['needs'];

    expect($needs)->not->toContain('frontend-tests')
        ->and($needs)->not->toContain('quality');
});

it('toda chave da matriz que o workflow lê existe em cada entrada que o seletor emite', function () {
    $text = (string) file_get_contents(CiHarness::root() . '/.github/workflows/integrator-contracts.yml');
    preg_match_all('/matrix\.(\w+)/', $text, $m);
    $read = array_unique($m[1]);

    foreach ([CiHarness::select('push', ''), CiHarness::selectWithChanged([])] as $out) {
        foreach ($out['matrix']['include'] as $job) {
            expect(array_keys($job))->toEqualCanonicalizing(['id', 'pest_args', 'shard', 'fixtures'])
                ->and(array_diff($read, array_keys($job)))->toBe([]);
        }
    }
});

it('os ids da matriz são únicos (nome do JUnit por fatia) e seguros para nome de arquivo', function () {
    foreach ([CiHarness::select('push', ''), CiHarness::selectWithChanged(CiHarness::testFiles(120))] as $out) {
        $ids = array_column($out['matrix']['include'], 'id');

        expect($ids)->toBe(array_values(array_unique($ids)));

        foreach ($ids as $id) {
            expect($id)->toMatch('/^[a-z0-9-]+$/');
        }
    }
});

it('as fixtures HTTP são validadas e publicadas só pelo job que tem o P2ContractsTest, mesmo se outro teste falhou', function () {
    $workflow = CiHarness::workflow();
    $steps    = $workflow['jobs']['php-tests']['steps'];
    $validate = collect($steps)->first(fn (array $step) => str_contains((string) ($step['run'] ?? ''), 'validate-integrator-contracts.php'));
    $upload   = collect($steps)->first(fn (array $step) => ($step['with']['name'] ?? '') === 'synthetic-integrator-contracts');

    foreach ([$validate, $upload] as $step) {
        expect($step['if'])->toContain('matrix.fixtures')
            ->and($step['if'])->toContain('!cancelled()')
            ->and($step['if'])->not->toContain('always()');
    }

    expect($upload['with']['path'])->toBe('storage/framework/testing/p2')
        ->and($upload['with']['retention-days'])->toBe(14); // dados sintéticos: higiene, como o JUnit

    // Nenhum outro job toca nas fixtures: um dono só.
    foreach ($workflow['jobs'] as $id => $job) {
        if ($id !== 'php-tests') {
            expect(json_encode($job))->not->toContain('validate-integrator-contracts')
                ->and(json_encode($job))->not->toContain('synthetic-integrator-contracts');
        }
    }
});

it('o JUnit de cada fatia é enviado com always() e nome único por fatia', function () {
    $steps = CiHarness::workflow()['jobs']['php-tests']['steps'];
    $junit = collect($steps)->first(fn (array $step) => str_starts_with((string) ($step['with']['name'] ?? ''), 'junit-'));

    expect($junit['if'])->toBe('always()')
        ->and($junit['with']['name'])->toBe('junit-${{ matrix.id }}');

    // O arquivo que o Pest grava é o diretório que o passo envia.
    [$pest] = ciwPestSteps(CiHarness::workflow()['jobs']['php-tests']);

    expect($pest['env']['JUNIT_DIR'])->toBe('${{ runner.temp }}/junit')
        ->and($junit['with']['path'])->toBe('${{ runner.temp }}/junit')
        ->and($pest['run'])->toContain('--log-junit="$JUNIT_DIR/$MATRIX_ID.xml"');
});

it('o build do cliente roda uma vez e os jobs de Pest baixam SÓ o public/build, onde o Laravel o procura', function () {
    $jobs     = CiHarness::workflow()['jobs'];
    $upload   = collect($jobs['assets']['steps'])->first(fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'actions/upload-artifact@'));
    $download = collect($jobs['php-tests']['steps'])->first(fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'actions/download-artifact@'));

    expect(collect($jobs['assets']['steps'])->pluck('run')->filter()->all())->toContain('npm ci')
        ->and($upload['with']['name'])->toBe('assets')
        // UM caminho: a raiz do artefato é o próprio public/build (upload-artifact), então o download cai no MESMO caminho.
        ->and($upload['with']['path'])->toBe('public/build')
        ->and($download['with'])->toBe(['name' => 'assets', 'path' => $upload['with']['path']])
        ->and($upload['with']['if-no-files-found'])->toBe('error')
        // public/build não tem arquivo oculto: o flag só alargaria o que pode entrar no pacote.
        ->and($upload['with'])->not->toHaveKey('include-hidden-files')
        // O TinyMCE só o navegador lê, e o bundle SSR nenhum teste PHP lê: nenhum dos dois viaja no artefato.
        ->and(json_encode($upload, JSON_UNESCAPED_SLASHES))->not->toContain('tinymce')
        ->and(json_encode($upload, JSON_UNESCAPED_SLASHES))->not->toContain('bootstrap/ssr');

    // O Pest confere o manifest (falha alto se o layout do artefato divergir) e nada mais do front.
    $checks = collect($jobs['php-tests']['steps'])->pluck('run')->filter(fn ($run) => str_contains((string) $run, 'public/'))->values()->all();

    expect($checks)->toBe(['test -f public/build/manifest.json'])
        ->and(json_encode($jobs['php-tests']))->not->toContain('tinymce');

    // O Vitest e o estilo não repetem o build do cliente, e o Pest não depende do node.
    foreach (['frontend-tests', 'quality'] as $id) {
        expect(collect($jobs[$id]['steps'])->pluck('run')->filter()->all())->not->toContain('node node_modules/vite/bin/vite.js build');
    }

    expect(json_encode($jobs['php-tests']))->not->toContain('npm ci')
        ->and(json_encode($jobs['php-tests']))->not->toContain('setup-node');
});

it('o `npm run build` do package.json continua inteiro na CI, dividido: cliente no assets, SSR no frontend-tests, mesma memória', function () {
    $package  = json_decode((string) file_get_contents(CiHarness::root() . '/package.json'), true, flags: JSON_THROW_ON_ERROR);
    $jobs     = CiHarness::workflow()['jobs'];
    $segments = array_values(array_filter(array_map('trim', explode('&&', $package['scripts']['build'])), fn (string $segment) => str_contains($segment, 'vite build')));

    expect($segments)->toHaveCount(2); // o cliente e o SSR; se o script mudar, este contrato precisa ser relido

    foreach ($segments as $segment) {
        preg_match("/^NODE_OPTIONS='([^']+)' vite (build( --ssr)?)$/", $segment, $m);

        $isSsr   = ($m[3] ?? '') !== '';
        $jobId   = $isSsr ? 'frontend-tests' : 'assets';
        $command = 'node node_modules/vite/bin/vite.js ' . $m[2];

        $holders = [];

        foreach ($jobs as $id => $job) {
            foreach ($job['steps'] as $step) {
                if (($step['run'] ?? '') === $command) {
                    $holders[$id] = $step['env']['NODE_OPTIONS'] ?? null;
                }
            }
        }

        expect([$segment => $holders])->toBe([$segment => [$jobId => $m[1]]]);
    }

    // Nenhum job roda o script inteiro (ele traria o tinymce:install e o SSR de volta ao caminho crítico).
    expect(array_filter(array_map(
        fn (array $job) => collect($job['steps'])->contains(fn (array $step) => str_contains((string) ($step['run'] ?? ''), 'npm run build')),
        $jobs,
    )))->toBe([]);

    // O SSR compila depois do Vitest e reprova o job se quebrar (passo comum, sem continue-on-error).
    $runs = collect($jobs['frontend-tests']['steps'])->pluck('run')->filter()->values()->all();

    expect(array_search('node node_modules/vitest/vitest.mjs run', $runs, true))->toBeLessThan(array_search('node node_modules/vite/bin/vite.js build --ssr', $runs, true));
});

it('nenhuma política de tempo ou de fatia depende do evento — só o seletor lê o evento', function () {
    $workflow = CiHarness::workflow();

    $byEvent = array_filter(array_map(
        fn (array $job) => str_contains((string) json_encode($job), 'event_name'),
        array_diff_key($workflow['jobs'], ['select' => 1]),
    ));

    expect($byEvent)->toBe([]);

    $select = collect($workflow['jobs']['select']['steps'])->firstWhere('id', 'select');

    expect($select['env']['CI_EVENT'])->toBe('${{ github.event_name }}')
        ->and(json_encode($workflow['jobs']['select']['outputs']))->not->toContain('event_name');
});

it('toda action é fixada por SHA de commit', function () {
    $text = (string) file_get_contents(CiHarness::root() . '/.github/workflows/integrator-contracts.yml');
    preg_match_all('/^\s*-?\s*uses:\s*(\S+)/m', $text, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $uses) {
        expect($uses)->toMatch('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/');
    }
});

it('o agregador `contracts` espera todos os jobs, roda sempre e só passa se TODOS deram success', function () {
    $workflow   = CiHarness::workflow();
    $aggregator = $workflow['jobs']['contracts'];
    $others     = array_values(array_diff(array_keys($workflow['jobs']), ['contracts']));

    expect($aggregator['if'])->toBe('always()')
        ->and($aggregator['needs'])->toEqualCanonicalizing($others)
        ->and($aggregator)->not->toHaveKey('continue-on-error');

    // Executa o script do passo de verdade, com o resultado de cada job vindo do ambiente (como no Actions).
    $script = $aggregator['steps'][0]['run'];
    $vars   = [];

    foreach ($aggregator['env'] as $name => $expression) {
        preg_match('/needs\.([\w-]+)\.result/', (string) $expression, $m);
        $vars[$m[1]] = $name;
    }

    expect(array_keys($vars))->toEqualCanonicalizing($others);

    $run = function (array $results) use ($script, $vars): int {
        $env = [];

        foreach ($vars as $job => $name) {
            $env[$name] = $results[$job] ?? '';
        }

        return (new Process(['bash', '-c', $script], null, $env))->run();
    };

    $allGreen = array_fill_keys($others, 'success');

    expect($run($allGreen))->toBe(0);

    $approved = [];

    foreach ($others as $job) {
        foreach (['failure', 'cancelled', 'skipped', ''] as $bad) {
            if ($run([...$allGreen, $job => $bad]) === 0) {
                $approved[] = "{$job}={$bad}";
            }
        }
    }

    expect($approved)->toBe([]); // nenhum resultado diferente de success pode aprovar o contracts
});

it('nenhum `run:` interpola expressão do GitHub: o que vem do PR chega ao shell só por `env:`', function () {
    $workflow = CiHarness::workflow();

    foreach ($workflow['jobs'] as $id => $job) {
        foreach ($job['steps'] as $index => $step) {
            if (isset($step['run'])) {
                expect(["{$id}[{$index}]" => str_contains($step['run'], '${{')])->toBe(["{$id}[{$index}]" => false]);
            }
        }
    }
});

it('todo checkout larga o token (persist-credentials: false) e só quem precisa de histórico baixa o histórico', function () {
    $workflow = CiHarness::workflow();
    $depths   = [];

    foreach ($workflow['jobs'] as $id => $job) {
        foreach ($job['steps'] as $step) {
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                expect([$id => $step['with']['persist-credentials'] ?? null])->toBe([$id => false]);

                $depths[$id] = $step['with']['fetch-depth'] ?? 1;
            }
        }
    }

    // `select` (diff contra a base) e `quality` (idem) precisam do histórico; o resto roda em clone raso. O contracts não faz checkout.
    expect($depths)->toBe(['select' => 0, 'assets' => 1, 'frontend-tests' => 1, 'quality' => 0, 'php-tests' => 1]);
});

it('os testes PHP só usam do git o que existe num clone raso (HEAD e a árvore vazia): nada de histórico, base ou remoto', function () {
    $root  = CiHarness::root();
    $found = [];

    foreach (['Feature', 'Unit', 'Integration'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/tests/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            // tests/Unit/Ci é a exceção declarada: só `rev-parse HEAD` e o diff contra a árvore vazia (CiHarness::EMPTY_TREE).
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Unit/Ci/')) {
                continue;
            }

            if (preg_match("/['\"]git['\"]\\s*,|\\bgit (log|diff|rev-list|fetch|show|merge-base|describe|blame|branch|tag)\\b|exec\\(['\"]git /", (string) file_get_contents($file->getPathname()))) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    expect($found)->toBe([]);

    // E os de tests/Unit/Ci só pedem HEAD ou a árvore vazia — nunca uma base remota nem log.
    foreach (glob("{$root}/tests/Unit/Ci/*.php") ?: [] as $path) {
        if (basename($path) === 'CiWorkflowTest.php') {
            continue; // cita os padrões acima no próprio contrato
        }

        $body = (string) file_get_contents($path);

        expect($body)->not->toMatch('/\bgit (log|fetch|merge-base|describe|show|blame)\b/')
            ->and($body)->not->toContain('origin/');
    }
});

it('privilégio mínimo: contents: read no workflow, nenhum job com escrita, e o agregador sem permissão nenhuma', function () {
    $workflow = CiHarness::workflow();

    expect($workflow['permissions'])->toBe(['contents' => 'read']);

    foreach ($workflow['jobs'] as $id => $job) {
        $effective = $job['permissions'] ?? $workflow['permissions'];

        // Todo escopo concedido é `read` (nada de write, id-token, pull-requests...).
        expect([$id => array_filter($effective, fn ($level) => $level !== 'read')])->toBe([$id => []]);
    }

    // O agregador não faz checkout nem chama a API: só lê o `needs.*.result` pelo ambiente.
    $contracts = $workflow['jobs']['contracts'];

    expect($contracts)->toHaveKey('permissions')
        ->and($contracts['permissions'])->toBe([])
        ->and(json_encode($contracts['steps']))->not->toContain('actions/checkout');
});

it('os jobs sem Pest têm teto de tempo FIXO no job; o do Pest segue vindo do seletor, no passo', function () {
    $workflow = CiHarness::workflow();

    foreach (array_diff_key($workflow['jobs'], ['php-tests' => 1]) as $id => $job) {
        expect([$id => is_int($job['timeout-minutes'] ?? null) && $job['timeout-minutes'] > 0 && $job['timeout-minutes'] <= 30])->toBe([$id => true]);
    }

    // O teto do job de Pest é do passo (seletor): um número fixo no job seria uma segunda política de tempo.
    expect($workflow['jobs']['php-tests'])->not->toHaveKey('timeout-minutes')
        ->and(ciwPestSteps($workflow['jobs']['php-tests'])[0]['timeout-minutes'])->toContain('needs.select.outputs.timeout_minutes');
});
