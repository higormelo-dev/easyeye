<?php

declare(strict_types=1);

/**
 * Filho de pcntl_fork() que trava ao sair, depois de o pai aquecer o pool OpenMP do Imagick (libgomp).
 *
 * O pai usa o Imagick com várias threads (testes de Feature de montagem de exames) e cria o pool do libgomp; o filho do
 * fork herda o estado do runtime SEM as threads, conclui o job e os callbacks PHP e bloqueia num futex dentro do
 * libgomp no encerramento nativo — `exit(0)` não termina. É o que fazia 5 cenários de IntegratorP2ConcurrencyTest
 * estourarem o prazo de 10s na suíte inteira e passarem isolados (`p2Concurrent` mata o filho e acusa "did not exit").
 *
 * A proteção é `OMP_THREAD_LIMIT=1` no ambiente INICIAL do processo do Pest (passo do workflow): o libgomp lê a
 * variável quando a biblioteca carrega, junto com a extensão, então `putenv()` e o `<env>` do phpunit.xml chegam tarde.
 * Cada caso roda num PHP novo — um processo já aquecido não serve de controle — e o filho do fork é sempre morto
 * com SIGKILL: nenhum caso deixa órfão nem passa de ~3s.
 *
 * Sem ext-imagick/pcntl/posix, ou sem /proc, não há o que provar: o caso é pulado dizendo o porquê.
 */
const OMP_FORK_CHILD_DEADLINE_SECONDS = 3;

const OMP_FORK_PROBE = <<<'PHP'
<?php
[, $pidFile, $deadline, $mode] = $argv;

if ($mode === 'putenv') {
    putenv('OMP_THREAD_LIMIT=1');
}

$threadsOf = fn (int $pid): int => preg_match('/^Threads:\s*(\d+)/m', (string) @file_get_contents("/proc/{$pid}/status"), $found) ? (int) $found[1] : 0;

// O limite explícito de 4 faz o que o pool de uma máquina com vários núcleos faz sozinho: criar threads no 1º trabalho.
Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 4);
$image = new Imagick();
$image->newImage(1500, 1500, new ImagickPixel('white'));
$image->setImageFormat('png');
$image->blurImage(2, 1);
$image->resizeImage(1000, 1000, Imagick::FILTER_LANCZOS, 1);
$image->getImageBlob();
$image->clear();

$warmThreads = $threadsOf(getmypid());
$pid         = pcntl_fork();

if ($pid === 0) {
    exit(0);
}

file_put_contents($pidFile, (string) $pid);
$started = microtime(true);
$outcome = 'exited';

while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
    if (microtime(true) - $started > (float) $deadline) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        $outcome = 'hung';

        break;
    }

    usleep(10000);
}

echo json_encode([
    'warm_threads' => $warmThreads,
    'child'        => $outcome,
    'exit_code'    => $outcome === 'exited' ? pcntl_wexitstatus($status) : null,
    'seconds'      => round(microtime(true) - $started, 2),
]);
PHP;

beforeEach(function () {
    foreach (['imagick', 'pcntl', 'posix'] as $extension) {
        if (! extension_loaded($extension)) {
            $this->markTestSkipped("ext-{$extension} ausente: sem ela não há pool OpenMP aquecido nem fork para provar.");
        }
    }

    if (PHP_OS_FAMILY !== 'Linux' || ! is_readable('/proc/self/status')) {
        $this->markTestSkipped('A prova lê o número de threads em /proc/<pid>/status (Linux).');
    }
});

/**
 * Roda a sonda num PHP novo, com o ambiente que o teste mandar, e devolve o que ela mediu:
 * warm_threads (threads do pai depois de usar o Imagick), child (exited|hung), exit_code, seconds.
 *
 * O ambiente herdado perde OMP_* e MAGICK_THREAD_LIMIT: na CI o workflow já exporta OMP_THREAD_LIMIT=1 e isso
 * esconderia o caso "sem proteção".
 */
function ompForkProbe(array $env = [], string $mode = 'plain'): array
{
    $dir = sys_get_temp_dir() . '/omp-fork-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    file_put_contents($dir . '/probe.php', OMP_FORK_PROBE);

    $inherited = array_filter(
        getenv(),
        fn (string $name): bool => ! str_starts_with($name, 'OMP_') && $name !== 'MAGICK_THREAD_LIMIT',
        ARRAY_FILTER_USE_KEY,
    );

    $process = proc_open(
        [PHP_BINARY, $dir . '/probe.php', $dir . '/child.pid', (string) OMP_FORK_CHILD_DEADLINE_SECONDS, $mode],
        [1 => ['pipe', 'w'], 2 => ['file', $dir . '/stderr', 'w']],
        $pipes,
        null,
        $inherited + $env,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('proc_open failed');
    }

    stream_set_blocking($pipes[1], false);
    $stdout    = '';
    $completed = false;

    try {
        $hardStop = microtime(true) + OMP_FORK_CHILD_DEADLINE_SECONDS + 10;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);

            if (! proc_get_status($process)['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);

                break;
            }

            if (microtime(true) > $hardStop) {
                throw new RuntimeException('a sonda não terminou nem depois do prazo do filho + 10s e foi morta');
            }

            usleep(20000);
        }

        $result = json_decode($stdout, true);

        if (! is_array($result)) {
            throw new RuntimeException('a sonda não devolveu o resultado: ' . trim($stdout . ' ' . (string) @file_get_contents($dir . '/stderr')));
        }

        $completed = true;

        return $result;
    } finally {
        $status = proc_get_status($process);

        if ($status['running']) {
            posix_kill($status['pid'], SIGKILL);
        }
        proc_close($process);

        // A sonda reapa o filho quando termina normalmente (o pid pode já ser de outro processo); só quando ela foi morta
        // no meio é que o filho pode ter ficado órfão e vivo.
        $childPid = (int) @file_get_contents($dir . '/child.pid');

        if (! $completed && $childPid > 0) {
            @posix_kill($childPid, SIGKILL);
        }

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
}

/**
 * Casos "sem proteção" só dizem algo se o pai realmente criou o pool: com 1 thread não há o que o filho herdar torto.
 */
function ompForkSkipUnlessWarmed(array $result): void
{
    if ($result['warm_threads'] <= 1) {
        test()->markTestSkipped("O Imagick não criou threads neste ambiente (pai com {$result['warm_threads']} thread): sem pool aquecido não há o que travar.");
    }
}

it('sem proteção, o filho do fork trava ao sair depois de o pai aquecer o OpenMP do Imagick (caracterização)', function () {
    $result = ompForkProbe();
    ompForkSkipUnlessWarmed($result);

    if ($result['child'] === 'exited') {
        $this->markTestSkipped('O libgomp deste ambiente não trava o filho do fork: a proteção não é necessária aqui.');
    }

    expect($result['child'])->toBe('hung');
});

it('com OMP_THREAD_LIMIT=1 no ambiente inicial, o pool nem nasce e o filho encerra em tempo curto', function () {
    $result = ompForkProbe(['OMP_THREAD_LIMIT' => '1']);

    expect($result['child'])->toBe('exited')
        ->and($result['exit_code'])->toBe(0)
        ->and($result['warm_threads'])->toBe(1)
        ->and($result['seconds'])->toBeLessThan(OMP_FORK_CHILD_DEADLINE_SECONDS);
});

it('OMP_THREAD_LIMIT definido depois de o PHP iniciar (putenv, <env> do phpunit.xml) não protege', function () {
    $result = ompForkProbe(mode: 'putenv');
    ompForkSkipUnlessWarmed($result);

    if ($result['child'] === 'exited') {
        $this->markTestSkipped('O libgomp deste ambiente não trava o filho do fork: a proteção não é necessária aqui.');
    }

    expect($result['child'])->toBe('hung');
});
