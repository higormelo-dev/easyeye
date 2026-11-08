<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * scripts/deploy-saveincloud.sh contra repositórios git temporários: um origin
 * "bare", um clone de desenvolvimento e um clone fazendo papel do servidor,
 * com php/composer/npm falsos que só registram a chamada. Situação do
 * levantamento de 08/11: .env 644, config/sentry.php copiado à mão (o git
 * recusaria o pull) e arquivos soltos na raiz.
 */
const DEPLOY_GIT_ENV = [
    'GIT_CONFIG_GLOBAL'   => '/dev/null',
    'GIT_CONFIG_NOSYSTEM' => '1',
    'GIT_AUTHOR_NAME'     => 'Teste',
    'GIT_AUTHOR_EMAIL'    => 'teste@example.com',
    'GIT_COMMITTER_NAME'  => 'Teste',
    'GIT_COMMITTER_EMAIL' => 'teste@example.com',
];

function deploySh(string $command, string $cwd): string
{
    $process = Process::fromShellCommandline($command, $cwd, DEPLOY_GIT_ENV);
    $process->mustRun();

    return trim($process->getOutput());
}

function deployWrite(string $path, string $content): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

/** @return string raiz do cenário: origin.git, dev/, app/ (servidor), bin/ e calls.log */
function deployScenario(): string
{
    $root = sys_get_temp_dir() . '/easyeye-deploy-' . bin2hex(random_bytes(4));
    mkdir($root);

    deploySh('git init -q --bare -b main origin.git && git init -q -b main dev', $root);
    deployWrite("{$root}/dev/artisan", "<?php\n");
    deployWrite("{$root}/dev/README.md", "v1\n");
    deploySh('git add -A && git commit -qm v1 && git remote add origin ../origin.git && git push -q origin main', "{$root}/dev");
    deploySh('git clone -q origin.git app', $root);

    // Servidor como estava: .env aberto, cópia manual do que vem no próximo commit,
    // outra cópia com conteúdo diferente e um arquivo solto de comando digitado errado.
    deployWrite("{$root}/app/.env", "APP_KEY=base64:x\n");
    chmod("{$root}/app/.env", 0644);
    deployWrite("{$root}/app/config/sentry.php", "<?php return ['dsn' => env('SENTRY_LARAVEL_DSN')];\n");
    deployWrite("{$root}/app/config/other.php", "<?php return ['manual' => true];\n");
    deployWrite("{$root}/app/11.12.0", '');

    deployWrite("{$root}/dev/config/sentry.php", "<?php return ['dsn' => env('SENTRY_LARAVEL_DSN')];\n");
    deployWrite("{$root}/dev/config/other.php", "<?php return ['manual' => false];\n");
    deploySh('git add -A && git commit -qm v2 && git push -q origin main', "{$root}/dev");

    foreach (['php', 'composer', 'npm'] as $bin) {
        $production = $bin === 'php' ? "[[ \"\$2\" == env ]] && echo '  INFO  The application environment is [production].'\n" : '';
        deployWrite("{$root}/bin/{$bin}", "#!/usr/bin/env bash\necho \"{$bin} \$*\" >> '{$root}/calls.log'\n{$production}exit 0\n");
        chmod("{$root}/bin/{$bin}", 0755);
    }

    return $root;
}

/** @param list<string> $args */
function runDeployScript(string $root, array $args = []): Process
{
    $process = new Process(['bash', dirname(__DIR__, 3) . '/scripts/deploy-saveincloud.sh', ...$args], "{$root}/app", DEPLOY_GIT_ENV + [
        'PHP'      => "{$root}/bin/php",
        'COMPOSER' => "{$root}/bin/composer",
        'NPM'      => "{$root}/bin/npm",
    ]);
    $process->run();

    return $process;
}

function deployCalls(string $root): string
{
    return is_file("{$root}/calls.log") ? (string) file_get_contents("{$root}/calls.log") : '';
}

beforeEach(function () {
    // Só repositórios temporários do próprio teste: roda no clone raso da CI (CiWorkflowTest).
    foreach (['flock', 'timeout', 'git'] as $tool) {
        if (trim((string) shell_exec('command -v ' . $tool)) === '') {
            $this->markTestSkipped("{$tool} indisponível (o script roda nos nós Linux da SaveInCloud)");
        }
    }

    $this->root = deployScenario();
});

afterEach(function () {
    if (isset($this->root)) {
        Process::fromShellCommandline('rm -rf ' . escapeshellarg($this->root))->run();
    }
});

it('atualiza pelo git: tira do caminho a cópia igual, guarda a diferente, fecha o .env e roda o checklist', function () {
    $deploy = runDeployScript($this->root);
    $app    = "{$this->root}/app";

    expect($deploy->getExitCode())->toBe(0, $deploy->getOutput() . $deploy->getErrorOutput())
        ->and(deploySh('git rev-parse HEAD', $app))->toBe(deploySh('git rev-parse origin/main', $app))
        ->and(deploySh('git status --porcelain --untracked-files=no', $app))->toBe('')
        ->and(file_get_contents("{$app}/config/other.php"))->toContain("'manual' => false")
        ->and(glob("{$app}/.easyeye-deploy-backups/*/config/sentry.php"))->toBe([]);

    $backup = glob("{$app}/.easyeye-deploy-backups/*/config/other.php");
    expect($backup)->toHaveCount(1)
        ->and(file_get_contents($backup[0]))->toContain("'manual' => true")
        ->and(substr(sprintf('%o', fileperms("{$app}/.env")), -3))->toBe('600')
        // Arquivo solto: só avisa (não é do repositório; uma pessoa decide).
        ->and($deploy->getErrorOutput())->toContain('11.12.0')
        ->and(is_file("{$app}/11.12.0"))->toBeTrue();

    $calls = deployCalls($this->root);
    expect($calls)->toContain('composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev')
        ->and($calls)->toContain('php artisan migrate --force')
        ->and($calls)->toContain('php artisan optimize')
        ->and($calls)->toContain('php artisan queue:restart')
        ->and($calls)->toContain('php artisan reverb:restart')
        ->and($calls)->not->toContain('npm ci');

    // Segunda vez: nada a atualizar.
    expect(runDeployScript($this->root)->getOutput())->toContain('nada a atualizar');
});

it('--dry-run só confere: código, .env e arquivos soltos ficam como estavam', function () {
    $app    = "{$this->root}/app";
    $before = deploySh('git rev-parse HEAD', $app);

    $deploy = runDeployScript($this->root, ['--dry-run']);

    expect($deploy->getExitCode())->toBe(0, $deploy->getErrorOutput())
        ->and(deploySh('git rev-parse HEAD', $app))->toBe($before)
        ->and(substr(sprintf('%o', fileperms("{$app}/.env")), -3))->toBe('644')
        ->and(file_get_contents("{$app}/config/other.php"))->toContain("'manual' => true")
        ->and(is_dir("{$app}/.easyeye-deploy-backups"))->toBeFalse()
        ->and($deploy->getOutput())->toContain('[dry-run] git merge --ff-only')
        ->and(deployCalls($this->root))->not->toContain('migrate');
});

it('arquivo rastreado alterado no servidor: para antes de mexer no código', function () {
    $app = "{$this->root}/app";
    file_put_contents("{$app}/README.md", "editado no servidor\n");
    $before = deploySh('git rev-parse HEAD', $app);

    $deploy = runDeployScript($this->root);

    expect($deploy->getExitCode())->not->toBe(0)
        ->and($deploy->getErrorOutput())->toContain('README.md')
        ->and(deploySh('git rev-parse HEAD', $app))->toBe($before)
        ->and(file_get_contents("{$app}/README.md"))->toBe("editado no servidor\n")
        ->and(file_get_contents("{$app}/config/other.php"))->toContain("'manual' => true")
        ->and(deployCalls($this->root))->not->toContain('migrate');
});

it('credencial na URL do remote nunca aparece na saída', function () {
    deploySh('git remote set-url origin http://deploy:tok-secreto@127.0.0.1:9/easyeye.git', "{$this->root}/app");

    $deploy = runDeployScript($this->root, ['--dry-run']);
    $output = $deploy->getOutput() . $deploy->getErrorOutput();

    expect($output)->not->toContain('tok-secreto')
        ->and($output)->toContain('http://***@127.0.0.1:9/easyeye.git');
});
