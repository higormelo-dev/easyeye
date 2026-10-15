<?php

declare(strict_types=1);

namespace Tests\Unit\Ci\Support;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Auxiliar dos contratos de CI (tests/Unit/Ci): roda o seletor (scripts/ci-select-tests.py) e o Pest de verdade, sem banco.
 *
 * `pest --list-tests` só descobre — não sobe o banco —, mas por garantia o processo filho recebe um Postgres inalcançável
 * (127.0.0.1:1): uma conexão acidental falha alto em vez de tocar o banco de quem roda a suíte. Classe (e não funções
 * globais do Pest) para não depender de qual arquivo de teste foi carregado na fatia.
 */
final class CiHarness
{
    /** A árvore vazia do git: um diff contra ela devolve todos os arquivos do repositório (PR de efeito amplo). */
    public const EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    public const FIXTURES_OWNER = 'tests/Feature/Api/Integrators/P2ContractsTest.php';

    /** @var array{classes: list<string>, cases: list<string>}|null */
    private static ?array $fullListing = null;

    public static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return array<string, mixed> */
    public static function workflow(): array
    {
        return Yaml::parseFile(self::root() . '/.github/workflows/integrator-contracts.yml');
    }

    /**
     * Roda o seletor de verdade e devolve o que ele escreveria em $GITHUB_OUTPUT (nunca no do job que roda este teste);
     * `matrix` já vem decodificada.
     *
     * @return array{mode: string, pest_args: string, timeout_minutes: string, matrix: array{include: list<array{id: string, pest_args: string, shard: string, fixtures: bool}>}}
     */
    public static function select(string $event, string $base): array
    {
        $output = (string) tempnam(sys_get_temp_dir(), 'ci-select-');

        try {
            (new Process(['python3', '-B', 'scripts/ci-select-tests.py'], self::root(), [
                'CI_EVENT'      => $event,
                'CI_BASE_SHA'   => $base,
                'GITHUB_OUTPUT' => $output,
            ]))->mustRun();

            return self::parseOutputs((string) file_get_contents($output));
        } finally {
            @unlink($output);
        }
    }

    /**
     * O `main()` do seletor com uma lista de arquivos alterados fabricada (o `git diff` é trocado por ela): o jeito
     * determinístico de provar uma seleção de tamanho conhecido pelo caminho real, sem depender do histórico do git.
     *
     * @param list<string> $changed
     * @param string       $setup   Python que roda ANTES do `main()` (com `module` já carregado): para simular o estado do disco
     *                              (um teste de nome estranho que o `rglob` do seletor encontra, por exemplo)
     *
     * @return array{mode: string, pest_args: string, timeout_minutes: string, matrix: array{include: list<array{id: string, pest_args: string, shard: string, fixtures: bool}>}}
     */
    public static function selectWithChanged(array $changed, string $setup = ''): array
    {
        $body = <<<'PY'
import contextlib, io, os, subprocess, tempfile
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
with contextlib.redirect_stdout(io.StringIO()):
    try:
        module.main()
    except SystemExit:
        pass
sys.stdout.write(open(output).read())
os.unlink(output)
PY;

        return self::parseOutputs(self::python($setup . "\n" . $body, [json_encode($changed, JSON_THROW_ON_ERROR)], decode: false));
    }

    /**
     * Executa $body com o módulo do seletor carregado como `module` (o nome do arquivo tem hífen: não dá para importar).
     * `python3 -B`: importar o módulo gravaria `scripts/__pycache__/` (fora do .gitignore) e sujaria o git de quem roda a suíte.
     *
     * @param list<string> $argv
     */
    public static function python(string $body, array $argv = [], bool $decode = true): mixed
    {
        $python = <<<'PY'
import importlib.util, json, sys
spec = importlib.util.spec_from_file_location('ci_select_tests', 'scripts/ci-select-tests.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

PY . $body;

        $stdout = (new Process(['python3', '-B', '-c', $python, ...$argv], self::root()))->mustRun()->getOutput();

        return $decode ? json_decode($stdout, true, flags: JSON_THROW_ON_ERROR) : $stdout;
    }

    /**
     * Arquivos de teste que existem de verdade, Feature e Unit misturados (o seletor os escolhe por si mesmos). Sem o
     * P2ContractsTest (o seletor o acrescenta sempre) e sem Integration (fica fora de PR).
     *
     * @return list<string>
     */
    public static function testFiles(int $count): array
    {
        $files = [];

        foreach (['Feature', 'Unit'] as $dir) {
            foreach ((new Finder())->files()->in(self::root() . "/tests/{$dir}")->name('*Test.php')->sortByName() as $file) {
                $path = "tests/{$dir}/" . str_replace('\\', '/', $file->getRelativePathname());

                if ($path !== self::FIXTURES_OWNER) {
                    $files[$dir][] = $path;
                }
            }
        }

        // Os últimos de Unit entram sempre: a seleção cruza a fronteira Feature → Unit, como a suíte inteira.
        $unit = array_slice($files['Unit'], -10);

        return array_merge(array_slice($files['Feature'], 0, max(0, $count - count($unit))), array_slice($unit, 0, $count));
    }

    /**
     * O que `pest <args> --list-tests` lista: as classes na ordem de aparição (o que o plugin de shard do Pest 4 enxerga) e
     * cada caso. Aceita `--shard=i/n` junto: quem lista é o próprio Pest, com o filtro que ele monta.
     *
     * @return array{classes: list<string>, cases: list<string>}
     */
    public static function listing(string ...$args): array
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/pest', ...$args, '--list-tests'], self::root(), [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
        ]);
        $process->setTimeout(180)->mustRun();

        $classes = $cases = [];

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (preg_match('/ - ((?:P\\\\)?(Tests\\\\[^:]+))::/', $line, $m)) {
                $classes[$m[2]] = true;
                // O Pest lista o nome de um caso terminado em "." sem o ponto na suíte inteira e com ele quando há --filter
                // (o que o --shard monta): só exibição, mas a comparação entre as duas listas precisa ignorá-lo.
                $cases[] = rtrim(trim($line), '.');
            }
        }

        return ['classes' => array_keys($classes), 'cases' => $cases];
    }

    /** @return array{classes: list<string>, cases: list<string>} */
    public static function fullListing(): array
    {
        return self::$fullListing ??= self::listing('tests/Feature', 'tests/Unit', 'tests/Integration');
    }

    /** `tests/Feature/A/BTest.php` → `Tests\Feature\A\BTest`: o nome de classe que o Pest lista para o arquivo. */
    public static function classOf(string $file): string
    {
        return str_replace('/', '\\', 'Tests' . substr(substr($file, 0, -4), 5));
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    public static function classesOf(array $files): array
    {
        return array_map(self::classOf(...), $files);
    }

    /** @return array{mode: string, pest_args: string, timeout_minutes: string, matrix: array{include: list<array{id: string, pest_args: string, shard: string, fixtures: bool}>}} */
    private static function parseOutputs(string $raw): array
    {
        $values = [];

        foreach (explode("\n", trim($raw)) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $values[$key]  = $value;
        }

        $values['matrix'] = json_decode($values['matrix'] ?? 'null', true, flags: JSON_THROW_ON_ERROR);

        return $values;
    }
}
