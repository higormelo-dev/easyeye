<?php

declare(strict_types=1);

/**
 * Contrato entre o seletor de testes da CI (scripts/ci-select-tests.py) e o workflow
 * (.github/workflows/integrator-contracts.yml).
 *
 * O teto de tempo do passo do Pest vem do seletor (`timeout_minutes`), que sabe quanto vai rodar. Antes ele vinha do
 * evento (pull_request = 15 min), e todo PR de efeito amplo (migrations, config, rotas, providers — a maioria dos PRs
 * de feature) roda a suíte inteira, uns 45 min: estourava sempre. Aqui ficam fixados o teto de cada cenário, o limite
 * da seleção grande e a ligação (id do passo e nome da saída) entre o script e o workflow.
 */

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function cisRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * Roda o seletor de verdade e devolve o que ele escreveria em $GITHUB_OUTPUT (nunca no do job que roda este teste).
 *
 * @return array<string, string>
 */
function cisRun(string $event, string $base): array
{
    $output = (string) tempnam(sys_get_temp_dir(), 'ci-select-');

    try {
        (new Process(['python3', 'scripts/ci-select-tests.py'], cisRoot(), [
            'CI_EVENT'      => $event,
            'CI_BASE_SHA'   => $base,
            'GITHUB_OUTPUT' => $output,
        ]))->mustRun();

        $values = [];

        foreach (explode("\n", trim((string) file_get_contents($output))) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $values[$key]  = $value;
        }

        return $values;
    } finally {
        @unlink($output);
    }
}

/**
 * Os números e a função de política do script, lidos do módulo (o nome do arquivo tem hífen: não dá para importar).
 * `python3 -B`: importar o módulo gravaria `scripts/__pycache__/` (fora do .gitignore) e sujaria o git de quem roda a suíte.
 *
 * @return array{short: int, long: int, limit: int, at_limit: int, above_limit: int, full: int}
 */
function cisPolicy(): array
{
    $python = <<<'PY'
import importlib.util, json
spec = importlib.util.spec_from_file_location('ci_select_tests', 'scripts/ci-select-tests.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
print(json.dumps({
    'short': module.SHORT_TIMEOUT,
    'long': module.LONG_TIMEOUT,
    'limit': module.SHORT_LIMIT,
    'at_limit': module.timeout_for('selected', module.SHORT_LIMIT),
    'above_limit': module.timeout_for('selected', module.SHORT_LIMIT + 1),
    'full': module.timeout_for('full', 0),
}))
PY;

    return json_decode((new Process(['python3', '-B', '-c', $python], cisRoot()))->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * O `main()` do seletor com uma lista de arquivos alterados fabricada (o `git diff` é trocado por ela): o único jeito
 * determinístico de provar o teto de uma seleção GRANDE pelo caminho real, sem depender do histórico do repositório.
 *
 * @param list<string> $changed
 *
 * @return array<string, string>
 */
function cisRunWithChanged(array $changed): array
{
    $python = <<<'PY'
import importlib.util, json, os, subprocess, sys, tempfile
spec = importlib.util.spec_from_file_location('ci_select_tests', 'scripts/ci-select-tests.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
changed = json.loads(sys.argv[1])
real_run = subprocess.run
def fake_run(cmd, *args, **kwargs):
    if cmd[:2] == ['git', 'diff']:
        return subprocess.CompletedProcess(cmd, 0, stdout='\n'.join(changed) + '\n', stderr='')
    return real_run(cmd, *args, **kwargs)
subprocess.run = fake_run
fd, output = tempfile.mkstemp()
os.close(fd)
os.environ.update(CI_EVENT='pull_request', CI_BASE_SHA='qualquer', GITHUB_OUTPUT=output)
try:
    module.main()
except SystemExit:
    pass
print(open(output).read())
os.unlink(output)
PY;

    $values = [];

    foreach (explode("\n", trim((new Process(['python3', '-B', '-c', $python, json_encode($changed)], cisRoot()))->mustRun()->getOutput())) as $line) {
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $values[$key]  = $value;
    }

    return $values;
}

/**
 * Arquivos de teste que existem de verdade (o seletor os escolhe por si mesmos).
 *
 * @return list<string>
 */
function cisTestFiles(int $count): array
{
    $files = [];

    foreach ((new Finder())->files()->in(cisRoot() . '/tests/Feature')->name('*Test.php')->sortByName() as $file) {
        $path = 'tests/Feature/' . str_replace('\\', '/', $file->getRelativePathname());

        if ($path !== 'tests/Feature/Api/Integrators/P2ContractsTest.php') {
            $files[] = $path;
        }
    }

    return array_slice($files, 0, $count);
}

it('o teto curto é mesmo menor que o longo', function () {
    $policy = cisPolicy();

    expect($policy['short'])->toBeLessThan($policy['long']);
});

it('push em main/desenv roda a suíte inteira com o teto longo', function () {
    $out = cisRun('push', '');

    expect($out)->toHaveKeys(['mode', 'pest_args', 'timeout_minutes'])
        ->and($out['mode'])->toBe('full')
        ->and($out['pest_args'])->toBe('tests/Feature tests/Unit tests/Integration')
        ->and($out['timeout_minutes'])->toBe((string) cisPolicy()['long']);
});

it('PR sem base conhecida roda a suíte inteira com o teto longo', function () {
    $out = cisRun('pull_request', '');

    expect($out['mode'])->toBe('full')
        ->and($out['timeout_minutes'])->toBe((string) cisPolicy()['long']);
});

it('PR de efeito amplo roda a suíte inteira COM o teto longo (o bug: 15 min para ~45 min de testes)', function () {
    // Diff contra a árvore vazia do git = todos os arquivos do repositório, entre eles composer.json, config/ e routes/.
    $out = cisRun('pull_request', '4b825dc642cb6eb9a060e54bf8d69288fbee4904');

    expect($out['mode'])->toBe('full')
        ->and($out['pest_args'])->toBe('tests/Feature tests/Unit tests/Integration')
        ->and($out['timeout_minutes'])->toBe((string) cisPolicy()['long']);
});

it('PR sem mudança em código roda só o P2ContractsTest com o teto curto', function () {
    $head = trim((new Process(['git', 'rev-parse', 'HEAD'], cisRoot()))->mustRun()->getOutput());
    $out  = cisRun('pull_request', $head);

    expect($out['mode'])->toBe('selected')
        ->and($out['pest_args'])->toBe('tests/Feature/Api/Integrators/P2ContractsTest.php')
        ->and($out['timeout_minutes'])->toBe((string) cisPolicy()['short']);
});

it('seleção pequena sai do seletor com o teto curto', function () {
    $out = cisRunWithChanged(cisTestFiles(3));

    expect($out['mode'])->toBe('selected')
        ->and($out['timeout_minutes'])->toBe((string) cisPolicy()['short']);
});

it('seleção grande sai do seletor com o teto longo (150 testes não cabem em 15 min)', function () {
    $policy  = cisPolicy();
    $changed = cisTestFiles($policy['limit'] + 20);
    $out     = cisRunWithChanged($changed);

    // O teste só vale se a seleção de fato passou do limite (o limite pode ser mexido no script).
    expect(count($changed))->toBeGreaterThan($policy['limit']);
    expect($out['mode'])->toBe('selected')
        ->and(count(explode(' ', $out['pest_args'])))->toBeGreaterThan($policy['limit'])
        ->and($out['timeout_minutes'])->toBe((string) $policy['long']);
});

it('seleção grande não cabe no teto curto: até o limite é curto, acima dele é longo', function () {
    $policy = cisPolicy();

    expect($policy['at_limit'])->toBe($policy['short'])
        ->and($policy['above_limit'])->toBe($policy['long'])
        ->and($policy['full'])->toBe($policy['long']);
});

it('o passo do Pest lê o teto da saída do seletor, não do evento', function () {
    $workflow = Yaml::parseFile(cisRoot() . '/.github/workflows/integrator-contracts.yml');
    $steps    = $workflow['jobs']['contracts']['steps'];

    $select = collect($steps)->firstWhere('id', 'select');
    $pest   = collect($steps)->first(fn (array $step) => str_contains((string) ($step['run'] ?? ''), 'vendor/bin/pest'));

    expect($select['run'])->toContain('scripts/ci-select-tests.py')
        ->and($pest['timeout-minutes'])->toContain('steps.select.outputs.')
        ->and($pest['timeout-minutes'])->not->toContain('github.event_name')
        ->and($pest['if'])->toContain('steps.select.outputs.pest_args')
        ->and($pest['env']['PEST_ARGS'])->toContain('steps.select.outputs.pest_args');

    // O nome que o workflow lê tem que ser um que o script de fato escreve (renomear só um dos lados cairia no 60 do
    // fallback sem avisar).
    preg_match('/steps\.select\.outputs\.(\w+)/', $pest['timeout-minutes'], $read);

    expect($read[1] ?? null)->toBe('timeout_minutes')
        ->and(cisRun('push', ''))->toHaveKey($read[1]);
});
