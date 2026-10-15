<?php

declare(strict_types=1);

/**
 * Contrato das fatias da CI: a `matrix` que o seletor (scripts/ci-select-tests.py) emite e que o workflow transforma em
 * jobs de Pest, cada um com o PRÓPRIO banco.
 *
 * A suíte inteira passou de um job de 40m37s (rodada 38775084843) para 6 jobs (Feature+Unit em 3 fatias, Integration em 3). Dividir é
 * perigoso de dois jeitos que estes testes fecham: (1) teste que some ou roda em dobro — a união das fatias tem que ser
 * exatamente a descoberta do Pest e as interseções vazias; (2) fatia vazia — o `--shard=i/n` do Pest 4 monta
 * `--filter ''` para ela, e filtro vazio seleciona TUDO. Quem confere a fatia é o Pest de verdade (`--list-tests` não
 * toca o banco), não a conta do script.
 */

use Symfony\Component\Process\Process;
use Tests\Unit\Ci\Support\CiHarness;

it('push, PR de efeito amplo e PR sem base emitem a MESMA matriz completa de 6 jobs', function () {
    $push   = CiHarness::select('push', '')['matrix'];
    $noBase = CiHarness::select('pull_request', '')['matrix'];
    $broad  = CiHarness::select('pull_request', CiHarness::EMPTY_TREE)['matrix'];

    expect($noBase)->toBe($push)
        ->and($broad)->toBe($push)
        ->and(array_column($push['include'], 'id'))->toBe([
            'feature-unit-1', 'feature-unit-2', 'feature-unit-3', 'integration-1', 'integration-2', 'integration-3',
        ])
        ->and(array_column($push['include'], 'shard'))->toBe(['1/3', '2/3', '3/3', '1/3', '2/3', '3/3'])
        ->and(array_column($push['include'], 'pest_args'))->toBe([
            'tests/Feature tests/Unit', 'tests/Feature tests/Unit', 'tests/Feature tests/Unit',
            'tests/Integration', 'tests/Integration', 'tests/Integration',
        ]);
});

it('a suíte inteira continua sendo Feature + Unit + Integration (Integration não sai por conveniência)', function () {
    $out  = CiHarness::select('push', '');
    $dirs = array_unique(array_merge(...array_map(fn (array $job) => explode(' ', $job['pest_args']), $out['matrix']['include'])));

    expect($out['pest_args'])->toBe('tests/Feature tests/Unit tests/Integration')
        ->and($dirs)->toEqualCanonicalizing(explode(' ', $out['pest_args']));
});

it('as saídas de antes continuam, com a matriz ao lado', function () {
    $out = CiHarness::select('push', '');

    expect(array_keys($out))->toBe(['mode', 'pest_args', 'timeout_minutes', 'matrix'])
        ->and($out['mode'])->toBe('full')
        ->and($out['timeout_minutes'])->toBe('60');
});

it('seleção pequena (até o limite) é UMA entrada, sem --shard', function () {
    $out = CiHarness::selectWithChanged(CiHarness::testFiles(3));

    // 3 + 1: o seletor acrescenta o P2ContractsTest.
    expect($out['mode'])->toBe('selected')
        ->and(count(explode(' ', $out['pest_args'])))->toBe(4)
        ->and($out['matrix']['include'])->toHaveCount(1)
        ->and($out['matrix']['include'][0])->toBe([
            'id'        => 'selected',
            'pest_args' => $out['pest_args'],
            'shard'     => '',
            'fixtures'  => true,
        ])
        ->and($out['timeout_minutes'])->toBe('15');
});

it('PR sem mudança em código roda só o P2ContractsTest, que é dono das fixtures', function () {
    $head = trim((new Process(['git', 'rev-parse', 'HEAD'], CiHarness::root()))->mustRun()->getOutput());
    $out  = CiHarness::select('pull_request', $head);

    expect($out['matrix']['include'])->toBe([
        ['id' => 'selected', 'pest_args' => CiHarness::FIXTURES_OWNER, 'shard' => '', 'fixtures' => true],
    ]);
});

it('seleção grande vira 3 fatias só de Feature/Unit — e o limite do teto de tempo é o mesmo do limite da matriz', function () {
    $limit   = CiHarness::python('print(json.dumps(module.SHORT_LIMIT))');
    $below   = CiHarness::selectWithChanged(CiHarness::testFiles($limit - 1)); // + P2ContractsTest = $limit
    $above   = CiHarness::selectWithChanged(CiHarness::testFiles($limit));     // + P2ContractsTest = $limit + 1
    $include = $above['matrix']['include'];
    $files   = explode(' ', $above['pest_args']);

    expect($below['matrix']['include'])->toHaveCount(1)
        ->and($below['timeout_minutes'])->toBe('15')
        ->and($include)->toHaveCount(3)
        ->and(array_column($include, 'id'))->toBe(['selected-1', 'selected-2', 'selected-3'])
        ->and(array_column($include, 'shard'))->toBe(['1/3', '2/3', '3/3'])
        ->and($above['timeout_minutes'])->toBe('60')
        ->and(count($files))->toBeGreaterThan($limit);

    foreach ($include as $job) {
        // Todas as fatias recebem a seleção inteira e o Pest corta (como na suíte inteira: carrega os mesmos arquivos).
        expect($job['pest_args'])->toBe($above['pest_args'])
            ->and($job['pest_args'])->not->toContain('tests/Integration');
    }

    expect(array_column($include, 'fixtures'))->toContain(true)
        ->and(array_filter(array_column($include, 'fixtures')))->toHaveCount(1);
});

it('a matriz é recusada (exit 1), nunca silenciosa, quando nenhum job é dono das fixtures', function () {
    $code = CiHarness::python(<<<'PY'
try:
    module.build_matrix('selected', ['tests/Feature/FooTest.php'])
    print(json.dumps(None))
except SystemExit as exc:
    print(json.dumps(exc.code))
PY);

    expect($code)->toBe(1);
});

it('um índice de fatia vazio nunca sai da matriz (o Pest rodaria `--filter ""` = a suíte inteira)', function (int $classes, int $total, array $expectedIndexes) {
    $files  = $classes === 0 ? [] : array_map(fn (int $i) => "tests/Feature/F{$i}Test.php", range(1, $classes));
    $shards = CiHarness::python(
        'print(json.dumps([[i, c] for i, c in module.pest_shards(json.loads(sys.argv[1]), int(sys.argv[2]))]))',
        [json_encode($files), (string) $total],
    );

    expect(array_column($shards, 0))->toBe($expectedIndexes)
        ->and(array_merge(...array_column($shards, 1)))->toBe($files); // cada classe uma vez, na ordem

    foreach ($shards as [, $chunk]) {
        expect($chunk)->not->toBeEmpty();
    }
})->with([
    '0 classes'                  => [0, 3, []],
    '1 classe, 3 fatias'         => [1, 3, [1]],
    '2 classes, 3 fatias'        => [2, 3, [1, 2]],
    '4 classes, 3 fatias'        => [4, 3, [1, 2]],
    '5 classes, 3 fatias'        => [5, 3, [1, 2, 3]],
    '5 classes, 4 fatias (ceil)' => [5, 4, [1, 2, 3]],
    '3 classes, 3 fatias'        => [3, 3, [1, 2, 3]],
    '7 classes, 3 fatias'        => [7, 3, [1, 2, 3]],
    '1 classe, 1 fatia'          => [1, 1, [1]],
]);

it('a descoberta estática do seletor é a do Pest: as mesmas classes na mesma ordem', function () {
    $static = CiHarness::python(
        'print(json.dumps(module.discover(tuple(module.FULL.split()))))',
    );

    expect(CiHarness::classesOf($static))->toBe(CiHarness::fullListing()['classes']);
});

it('as 6 fatias da suíte inteira cobrem cada caso uma única vez, e o P2ContractsTest cai em UM só job (o dono das fixtures)', function () {
    $matrix = CiHarness::select('push', '')['matrix']['include'];
    $full   = CiHarness::fullListing();
    $owner  = CiHarness::classOf(CiHarness::FIXTURES_OWNER);

    $cases = $classes = $owners = [];

    foreach ($matrix as $job) {
        $listing = CiHarness::listing(...explode(' ', $job['pest_args']), ...['--shard=' . $job['shard']]);

        // Fatia vazia = o Pest lista tudo (filtro vazio). Uma fatia da suíte inteira nunca pode ser a suíte inteira.
        expect($listing['classes'])->not->toBeEmpty()
            ->and(count($listing['classes']))->toBeLessThan(count($full['classes']));

        foreach ($listing['cases'] as $case) {
            $cases[] = $case;
        }

        foreach ($listing['classes'] as $class) {
            $classes[] = $class;
        }

        if (in_array($owner, $listing['classes'], true)) {
            $owners[] = $job['id'];
        }
    }

    // união == descoberta; interseção vazia (nada repetido, nada perdido)
    expect($classes)->toHaveCount(count($full['classes']))
        ->and($classes)->toEqualCanonicalizing($full['classes'])
        ->and($cases)->toHaveCount(count($full['cases']))
        ->and($cases)->toEqualCanonicalizing($full['cases']);

    // O P2ContractsTest roda em exatamente um job, e é o que a matriz marca como dono das fixtures.
    $marked = array_column(array_filter($matrix, fn (array $job) => $job['fixtures']), 'id');

    expect($owners)->toHaveCount(1)
        ->and($marked)->toBe($owners);
});

it('as fatias da seleção grande cobrem a seleção uma única vez e o dono das fixtures é o job que de fato a tem', function () {
    $limit  = CiHarness::python('print(json.dumps(module.SHORT_LIMIT))');
    $out    = CiHarness::selectWithChanged(CiHarness::testFiles($limit + 20));
    $wanted = CiHarness::classesOf(explode(' ', $out['pest_args']));
    $owner  = CiHarness::classOf(CiHarness::FIXTURES_OWNER);

    $classes = $owners = [];

    foreach ($out['matrix']['include'] as $job) {
        $listing = CiHarness::listing(...explode(' ', $job['pest_args']), ...['--shard=' . $job['shard']]);

        expect($listing['classes'])->not->toBeEmpty()
            ->and(count($listing['classes']))->toBeLessThan(count($wanted));

        $classes = array_merge($classes, $listing['classes']);

        if (in_array($owner, $listing['classes'], true)) {
            $owners[] = $job['id'];
        }
    }

    expect($classes)->toHaveCount(count($wanted))
        ->and($classes)->toEqualCanonicalizing($wanted)
        ->and($owners)->toHaveCount(1)
        ->and(array_column(array_filter($out['matrix']['include'], fn (array $job) => $job['fixtures']), 'id'))->toBe($owners);
});

it('as contas de fatia do script batem com o `--shard` do Pest também em tamanhos pequenos e não divisíveis (5 classes em 4 fatias)', function () {
    // 5 classes / 4 fatias → ceil(5/4) = 2 por fatia → 3 fatias com arquivo ([2, 2, 1]); a 4ª seria vazia.
    $files   = CiHarness::testFiles(5);
    $planned = CiHarness::python(
        'print(json.dumps(module.pest_shards(json.loads(sys.argv[1]), 4)))',
        [json_encode($files)],
    );

    expect(array_column($planned, 0))->toBe([1, 2, 3]);

    foreach ($planned as [$index, $chunk]) {
        $listing = CiHarness::listing(...$files, ...["--shard={$index}/4"]);

        expect($listing['classes'])->toBe(CiHarness::classesOf($chunk));
    }
});
