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
 *  - fixtures HTTP validadas só no job que as gera; JUnit de cada fatia enviado mesmo em falha.
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

it('a extensão redis está na lista do setup-php de todo job que sobe o Laravel', function () {
    $workflow = CiHarness::workflow();

    foreach (['php-tests', 'quality'] as $id) {
        $setup      = collect($workflow['jobs'][$id]['steps'])->first(fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'shivammathur/setup-php@'));
        $extensions = array_map('trim', explode(',', $setup['with']['extensions']));

        expect($extensions)->toContain('redis', 'pdo_pgsql', 'pcntl', 'posix', 'gd', 'sodium');
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
        ->and($command)->not->toContain('--parallel')
        ->and($command)->not->toMatch('/\s-p(\s|$)/');
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

    expect($upload['with']['path'])->toBe('storage/framework/testing/p2');

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

it('o build roda uma vez e os jobs de Pest baixam o resultado onde o Laravel o procura', function () {
    $jobs     = CiHarness::workflow()['jobs'];
    $upload   = collect($jobs['assets']['steps'])->first(fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'actions/upload-artifact@'));
    $download = collect($jobs['php-tests']['steps'])->first(fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'actions/download-artifact@'));

    expect(collect($jobs['assets']['steps'])->pluck('run')->filter()->all())->toContain('npm ci', 'npm run build')
        ->and($upload['with']['name'])->toBe('assets')
        ->and($upload['with']['path'])->toContain('public/build')
        ->and($upload['with']['path'])->toContain('public/vendor/tinymce')
        ->and($upload['with']['include-hidden-files'])->toBeTrue()
        ->and($upload['with']['if-no-files-found'])->toBe('error')
        ->and($download['with'])->toBe(['name' => 'assets', 'path' => 'public']);

    // O Vitest e o estilo não repetem o build, e o Pest não depende do node.
    foreach (['frontend-tests', 'quality'] as $id) {
        expect(collect($jobs[$id]['steps'])->pluck('run')->filter()->all())->not->toContain('npm run build');
    }

    expect(json_encode($jobs['php-tests']))->not->toContain('npm ci')
        ->and(json_encode($jobs['php-tests']))->not->toContain('setup-node');
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
