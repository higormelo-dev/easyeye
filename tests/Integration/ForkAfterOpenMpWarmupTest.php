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
 * Cada caso roda num PHP novo — um processo já aquecido não serve de controle.
 *
 * Processos e prazos. A SONDA (PHP novo) vira líder de sessão/grupo ANTES de qualquer fork, aquece o Imagick, faz o fork
 * e supervisiona o filho com o PID que o fork devolveu (ainda não reapado, logo ainda dele): o filho que passa de 3s é
 * morto e reapado ali mesmo. O WRAPPER (`ompRunProbe`) é o dono da sonda: passado o prazo da sonda (3s do filho + 10s de
 * folga, fora a partida do PHP e o aquecimento) ou quando a sonda termina, ele encerra o GRUPO inteiro (`kill(-pgid)`)
 * com a sonda ainda sem reapar — o líder não reapado segura o número, então o grupo é sempre o dela e nunca o do Pest —
 * e só depois a reapa. Nenhum PID é lido de arquivo nem sinalizado depois de reapado. O que isto promete: nada que a
 * sonda tenha criado e não tenha mudado de grupo/sessão por conta própria sobrevive ao wrapper, qualquer que seja o
 * caminho (sucesso, timeout, sonda morta depois do fork). O que NÃO promete: processo que escape do grupo (outra
 * `setsid`) nem processo preso em estado D.
 *
 * Sem ext-pcntl/posix, ou sem /proc, não há o que provar: o caso é pulado dizendo o porquê. Os casos de
 * supervisão não precisam de Imagick (rodam também onde ele não está carregado); só os três do pool aquecido precisam.
 */
const OMP_FORK_CHILD_DEADLINE_SECONDS = 3;

// Só o que a sonda precisa para achar o PHP e as mesmas extensões/bibliotecas do processo do teste. Nada de DB_PASSWORD,
// SENTRY_DSN, APP_KEY ou token: o ambiente NÃO é herdado. OMP_* e MAGICK_THREAD_LIMIT ficam de fora de propósito (na CI o
// workflow exporta OMP_THREAD_LIMIT=1 e isso esconderia o caso "sem proteção"); quem quiser uma variável a passa em $env.
const OMP_PROBE_ENV_ALLOWLIST = ['PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'TZ', 'LD_LIBRARY_PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'MAGICK_CONFIGURE_PATH', 'MAGICK_HOME'];

const OMP_FORK_PROBE = <<<'PHP'
<?php
// Argumentos: prazo do filho (s), warm|cold (aquece o Imagick), plain|putenv, ação do filho.
[, $deadline, $warm, $envMode, $childAction] = $argv;

// Sessão e grupo PRÓPRIOS antes de qualquer fork: o wrapper encerra a sonda e todos os descendentes por grupo.
if (posix_setsid() === -1) {
    fwrite(STDERR, 'posix_setsid failed: ' . posix_strerror(posix_get_last_error()));
    exit(2);
}

if ($envMode === 'putenv') {
    putenv('OMP_THREAD_LIMIT=1');
}

$threadsOf = fn (int $pid): int => preg_match('/^Threads:\s*(\d+)/m', (string) @file_get_contents("/proc/{$pid}/status"), $found) ? (int) $found[1] : 0;

if ($warm === 'warm') {
    // O limite explícito de 4 faz o que o pool de uma máquina com vários núcleos faz sozinho: criar threads no 1º trabalho.
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 4);
    $image = new Imagick();
    $image->newImage(1500, 1500, new ImagickPixel('white'));
    $image->setImageFormat('png');
    $image->blurImage(2, 1);
    $image->resizeImage(1000, 1000, Imagick::FILTER_LANCZOS, 1);
    $image->getImageBlob();
    $image->clear();
}

$warmThreads = $threadsOf(getmypid());
$pid  = null; // o que pcntl_fork() devolveu (-1 = falhou); sai no JSON para os testes distinguirem "fork falhou" de "fork ok"
$emit = function (string $child, ?int $exitCode, ?int $signal, float $started) use ($warmThreads, &$pid): never {
    echo json_encode([
        'pid'          => getmypid(),
        'pgid'         => posix_getpgid(0),
        'sid'          => posix_getsid(0),
        'warm_threads' => $warmThreads,
        'fork_result'  => $pid,
        'child'        => $child,
        'exit_code'    => $exitCode,
        'signal'       => $signal,
        'seconds'      => round(microtime(true) - $started, 2),
    ]);

    exit(0);
};

if ($childAction === 'nproc') {
    // Sem processos sobrando no limite do usuário, o fork falha com EAGAIN (ignorado por root/CAP_SYS_RESOURCE).
    posix_setrlimit(POSIX_RLIMIT_NPROC, 1, 1);
}

$started = microtime(true);
$pid     = @pcntl_fork();

if ($pid === -1) {
    $emit('fork_failed', null, null, $started);
}

if ($pid === 0) {
    if ($childAction === 'hang') {
        sleep(999);
    }

    if ($childAction === 'exit3') {
        exit(3);
    }

    if (str_starts_with($childAction, 'signal:')) {
        posix_kill(getmypid(), constant(substr($childAction, 7)));
    }

    exit(0);
}

// Daqui em diante $pid é um filho NOSSO e ainda não reapado: o número não pode ter sido reaproveitado.
while (true) {
    $waited = pcntl_waitpid($pid, $status, WNOHANG);

    if ($waited === $pid) {
        // Sucesso é só isto: o waitpid devolveu ESTE filho, ele terminou normalmente (não por sinal) e com status 0.
        if (pcntl_wifexited($status)) {
            $code = pcntl_wexitstatus($status);

            $emit($code === 0 ? 'exited' : 'exit_code', $code, null, $started);
        }

        if (pcntl_wifsignaled($status)) {
            $emit('signaled', null, pcntl_wtermsig($status), $started);
        }

        $emit('unknown_status', null, null, $started);
    }

    if ($waited !== 0) {
        $emit('waitpid_failed', null, null, $started);
    }

    if (microtime(true) - $started > (float) $deadline) {
        // waitpid acabou de devolver 0: o filho ainda não foi reapado (no máximo virou zumbi), o número continua dele.
        posix_kill($pid, SIGKILL);
        $reapBy = microtime(true) + 5;

        while (($reaped = pcntl_waitpid($pid, $status, WNOHANG)) === 0) {
            if (microtime(true) > $reapBy) {
                $emit('unreaped', null, null, $started);
            }

            usleep(10000);
        }

        $emit($reaped === $pid ? 'hung' : 'waitpid_failed', null, null, $started);
    }

    usleep(10000);
}
PHP;

// Cenários só do supervisor (wrapper): nada de Imagick aqui.
const OMP_SUPERVISION_FIXTURE = <<<'PHP'
<?php
[, $mode] = $argv;

if ($mode === 'env') {
    echo json_encode(['env' => array_keys(getenv())]);
    exit(0);
}

if ($mode === 'hang-before-setsid') {
    sleep(999);
    exit(0);
}

posix_setsid();

// Um filho que viveria muito mais que o teste: só o kill por grupo o alcança.
if (pcntl_fork() === 0) {
    sleep(999);
    exit(0);
}

if ($mode === 'die-after-fork') {
    posix_kill(getmypid(), SIGKILL);
}

sleep(999);
PHP;

beforeEach(function () {
    foreach (['pcntl', 'posix'] as $extension) {
        if (! extension_loaded($extension)) {
            $this->markTestSkipped("ext-{$extension} ausente: sem ela não há fork nem grupo de processos para provar.");
        }
    }

    if (PHP_OS_FAMILY !== 'Linux' || ! is_readable('/proc/self/status')) {
        $this->markTestSkipped('A prova lê o estado dos processos em /proc/<pid>/stat e /proc/<pid>/status (Linux).');
    }
});

function ompRequireImagick(): void
{
    if (! extension_loaded('imagick')) {
        test()->markTestSkipped('ext-imagick ausente: sem ela não há pool OpenMP aquecido para provar.');
    }
}

/**
 * Estado de um processo em /proc/<pid>/stat: state (R, S, Z...), ppid, pgrp e session; null se o processo não existe.
 * O nome do executável fica entre parênteses e pode ter espaço ou ')': por isso se corta no ÚLTIMO ')'.
 *
 * @return array{state: string, ppid: int, pgrp: int, session: int}|null
 */
function ompProcStat(int $pid): ?array
{
    // file_exists antes de ler: o PHPUnit lista como "warning" até a leitura suprimida (@) de um processo que já saiu.
    $stat = file_exists("/proc/{$pid}/stat") ? @file_get_contents("/proc/{$pid}/stat") : false;

    if ($stat === false || ($end = strrpos($stat, ')')) === false) {
        return null;
    }

    $fields = explode(' ', trim(substr($stat, $end + 2)));

    return ['state' => $fields[0], 'ppid' => (int) $fields[1], 'pgrp' => (int) $fields[2], 'session' => (int) $fields[3]];
}

/**
 * Processos VIVOS que sobraram de uma rodada da sonda (zumbi não conta: já morreu, só falta o init reapá-lo). Mapa pid => estado.
 * Acha por DOIS caminhos: pertencer ao grupo/sessão da sonda, ou ter o diretório único da rodada no cmdline — o segundo
 * enxerga também o descendente que ficou FORA do grupo (sonda sem setsid, ou setsid depois do fork).
 *
 * @param array{group: int, marker: string} $run
 *
 * @return array<int, string>
 */
function ompSurvivors(array $run): array
{
    $found = [];

    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
        $pid  = (int) basename($dir);
        $stat = ompProcStat($pid);

        if ($stat === null || $stat['state'] === 'Z') {
            continue;
        }

        $cmdline = file_exists("{$dir}/cmdline") ? (string) @file_get_contents("{$dir}/cmdline") : '';

        if ($stat['pgrp'] === $run['group'] || $stat['session'] === $run['group'] || str_contains($cmdline, $run['marker'])) {
            $found[$pid] = $stat['state'];
        }
    }

    return $found;
}

/**
 * Espera um pouco (o init reapa os órfãos de forma assíncrona) e reprova se sobrou processo vivo da rodada.
 *
 * @param array{group: int, marker: string} $run
 */
function ompAssertNoSurvivors(array $run): void
{
    $until = microtime(true) + 3;

    do {
        $alive = ompSurvivors($run);

        if ($alive === []) {
            expect($alive)->toBe([]);

            return;
        }

        usleep(50000);
    } while (microtime(true) < $until);

    foreach (array_keys($alive) as $pid) {
        posix_kill($pid, SIGKILL); // não deixa lixo para as próximas rodadas
    }

    test()->fail('Sobraram processos vivos da sonda ' . $run['group'] . ': ' . json_encode($alive));
}

/**
 * Roda um script PHP novo como SONDA supervisionada e devolve o JSON que ela imprimiu.
 *
 * O wrapper é o dono do ciclo de vida: não há PID gravado em arquivo nem sinal para PID reapado. Ele espera a sonda
 * terminar OU o prazo, SEM reapá-la (lê o estado em /proc: zumbi = terminou e continua segurando o PID), e só então
 * mata o grupo todo (kill(-pgid)) e a própria sonda (ela pode ainda não ter virado líder) — tudo com o PID ancorado
 * pelo zumbi/processo vivo — e por fim reapa. Matar o grupo depois do reap seria arriscar um número reaproveitado.
 *
 * $run recebe o id do grupo (= PID da sonda) e o marcador único da rodada (o diretório, que aparece no cmdline da sonda e de
 * todo descendente que não troque de imagem) para os testes conferirem que nada sobrou, mesmo quando a função lança.
 *
 * @param list<string>          $args
 * @param array<string, string> $env  acrescenta ao ambiente mínimo (OMP_PROBE_ENV_ALLOWLIST); nunca herda o resto
 *
 * @return array<string, mixed>
 */
function ompRunProbe(string $script, array $args = [], array $env = [], float $hardStopSeconds = OMP_FORK_CHILD_DEADLINE_SECONDS + 10, ?array &$run = null): array
{
    $group = null;
    $dir   = sys_get_temp_dir() . '/omp-fork-' . bin2hex(random_bytes(6));
    $run   = ['group' => -1, 'marker' => $dir . '/probe.php'];

    if (! @mkdir($dir, 0700)) {
        throw new RuntimeException("não foi possível criar o diretório temporário da sonda ({$dir})");
    }

    $stdout    = '';
    $stderr    = '';
    $timedOut  = false;
    $vanished  = false;
    $exitCode  = null;
    $pipes     = [];
    $process   = false;
    $startedAt = microtime(true);

    try {
        if (file_put_contents($dir . '/probe.php', $script) === false) {
            throw new RuntimeException("não foi possível gravar o script da sonda em {$dir}");
        }

        $process = proc_open(
            [PHP_BINARY, $dir . '/probe.php', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $dir . '/stderr', 'w']],
            $pipes,
            null,
            array_intersect_key(getenv(), array_flip(OMP_PROBE_ENV_ALLOWLIST)) + $env,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('proc_open falhou ao iniciar a sonda');
        }

        // A sonda acabou de nascer: este é o único proc_get_status, e ela não terminou (se tivesse, ele a reaparia).
        $info         = proc_get_status($process);
        $group        = $info['pid'];
        $run['group'] = $group;

        if (! $info['running']) {
            $vanished = true; // já reapada pelo próprio proc_get_status: não há PID ancorado para sinalizar
        }

        stream_set_blocking($pipes[1], false);
        $deadline = $startedAt + $hardStopSeconds;

        while (! $vanished) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stat = ompProcStat($group);

            if ($stat === null) {
                $vanished = true; // reapada por fora (SIGCHLD ignorado?): nada a ancorar

                break;
            }

            if ($stat['state'] === 'Z') {
                break; // terminou; segue sem reapar até matar o grupo
            }

            if (microtime(true) > $deadline) {
                $timedOut = true;

                break;
            }

            usleep(20000);
        }
    } finally {
        if ($group !== null && ! $vanished) {
            // Sonda viva ou zumbi (nunca reapada): $group ainda é o PID dela. -pgid alcança os descendentes (inclusive o
            // órfão que ela deixou se morreu depois do fork); o kill direto cobre a janela antes de ela virar líder.
            posix_kill(-$group, SIGKILL);
            posix_kill($group, SIGKILL);
        }

        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }

        if (is_resource($process)) {
            $exitCode = proc_close($process); // aqui a sonda é reapada
        }

        $stderr = trim((string) @file_get_contents($dir . '/stderr'));
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    if ($timedOut) {
        throw new RuntimeException('a sonda não terminou dentro de ' . $hardStopSeconds . 's e o grupo foi encerrado');
    }

    $result = json_decode($stdout, true);

    if (! is_array($result)) {
        throw new RuntimeException('a sonda não devolveu o resultado (status ' . var_export($exitCode, true) . '): ' . trim($stdout . ' ' . $stderr));
    }

    if ($exitCode !== 0) {
        throw new RuntimeException('a sonda respondeu mas terminou com status ' . var_export($exitCode, true) . ': ' . $stderr);
    }

    return $result;
}

/**
 * A sonda do fork (OMP_FORK_PROBE): warm_threads (threads do pai depois de usar o Imagick), child
 * (exited|exit_code|signaled|hung|fork_failed|waitpid_failed|unreaped|unknown_status), exit_code, signal, seconds.
 *
 * @param array<string, string> $env
 *
 * @return array<string, mixed>
 */
function ompForkProbe(array $env = [], string $mode = 'plain', string $warm = 'warm', string $childAction = 'exit0', int $childDeadline = OMP_FORK_CHILD_DEADLINE_SECONDS, ?array &$run = null): array
{
    return ompRunProbe(OMP_FORK_PROBE, [(string) $childDeadline, $warm, $mode, $childAction], $env, $childDeadline + 10, $run);
}

/**
 * Casos "sem proteção" só dizem algo se o pai realmente criou o pool: com 1 thread não há o que o filho herdar torto.
 *
 * @param array<string, mixed> $result
 */
function ompForkSkipUnlessWarmed(array $result): void
{
    if ($result['warm_threads'] <= 1) {
        test()->markTestSkipped("O Imagick não criou threads neste ambiente (pai com {$result['warm_threads']} thread): sem pool aquecido não há o que travar.");
    }
}

it('sem proteção, o filho do fork trava ao sair depois de o pai aquecer o OpenMP do Imagick (caracterização)', function () {
    ompRequireImagick();
    $result = ompForkProbe(run: $run);
    ompForkSkipUnlessWarmed($result);

    if ($result['child'] === 'exited') {
        $this->markTestSkipped('O libgomp deste ambiente não trava o filho do fork: a proteção não é necessária aqui.');
    }

    expect($result['child'])->toBe('hung');
    ompAssertNoSurvivors($run);
});

it('com OMP_THREAD_LIMIT=1 no ambiente inicial, o pool nem nasce e o filho encerra em tempo curto', function () {
    ompRequireImagick();
    $result = ompForkProbe(['OMP_THREAD_LIMIT' => '1'], run: $run);

    expect($result['child'])->toBe('exited')
        ->and($result['exit_code'])->toBe(0)
        ->and($result['signal'])->toBeNull()
        ->and($result['warm_threads'])->toBe(1)
        ->and($result['seconds'])->toBeLessThan(OMP_FORK_CHILD_DEADLINE_SECONDS);
    ompAssertNoSurvivors($run);
});

it('OMP_THREAD_LIMIT definido depois de o PHP iniciar (putenv, <env> do phpunit.xml) não protege', function () {
    ompRequireImagick();
    $result = ompForkProbe(mode: 'putenv', run: $run);
    ompForkSkipUnlessWarmed($result);

    if ($result['child'] === 'exited') {
        $this->markTestSkipped('O libgomp deste ambiente não trava o filho do fork: a proteção não é necessária aqui.');
    }

    expect($result['child'])->toBe('hung');
    ompAssertNoSurvivors($run);
});

// SIGKILL e SIGTERM em vez de SIGSEGV: matam igual (sem handler no PHP CLI) e não geram core dump nem apport no host.
it('filho morto por sinal NUNCA vira sucesso, nem com status de saída zero', function (string $signalName, int $signalNumber) {
    $result = ompForkProbe(warm: 'cold', childAction: 'signal:' . $signalName, run: $run);

    // wexitstatus() de um término por sinal é 0: sem exigir wifexited() isto passava como "exited / 0".
    expect($result['child'])->toBe('signaled')
        ->and($result['signal'])->toBe($signalNumber)
        ->and($result['exit_code'])->toBeNull();
    ompAssertNoSurvivors($run);
})->with([
    'SIGKILL' => ['SIGKILL', 9],
    'SIGTERM' => ['SIGTERM', 15],
]);

it('filho que sai com status diferente de zero não é sucesso', function () {
    $result = ompForkProbe(warm: 'cold', childAction: 'exit3', run: $run);

    expect($result['child'])->toBe('exit_code')->and($result['exit_code'])->toBe(3)->and($result['signal'])->toBeNull();
    ompAssertNoSurvivors($run);
});

it('filho que sai normalmente com status zero é o único sucesso', function () {
    $result = ompForkProbe(warm: 'cold', run: $run);

    expect($result['child'])->toBe('exited')->and($result['exit_code'])->toBe(0)->and($result['signal'])->toBeNull();
    // A sonda é líder de sessão e de grupo (o wrapper a conhece por esse número), ou o kill por grupo não a alcança.
    expect($result['pgid'])->toBe($result['pid'])->and($result['sid'])->toBe($result['pid'])->and($result['pid'])->toBe($run['group']);
    ompAssertNoSurvivors($run);
});

it('pcntl_fork() que falha (-1) é rejeitado, não vira "waitpid de qualquer filho"', function () {
    $result = ompForkProbe(warm: 'cold', childAction: 'nproc', run: $run);

    if ($result['fork_result'] > 0) {
        $this->markTestSkipped('RLIMIT_NPROC não barrou o fork neste ambiente (root ou CAP_SYS_RESOURCE): não há como forçar o -1.');
    }

    // fork_result -1 + "exited" seria o falso verde: waitpid(-1) também devolve -1 e "bate" com o PID do fork que falhou.
    expect($result['fork_result'])->toBe(-1)->and($result['child'])->toBe('fork_failed')->and($result['exit_code'])->toBeNull();
    ompAssertNoSurvivors($run);
});

it('a sonda mata e reapa o filho que passa do prazo, sem deixar processo', function () {
    $result = ompForkProbe(warm: 'cold', childAction: 'hang', childDeadline: 1, run: $run);

    expect($result['child'])->toBe('hung')->and($result['seconds'])->toBeGreaterThanOrEqual(1.0)->and($result['seconds'])->toBeLessThan(4.0);
    ompAssertNoSurvivors($run);
});

it('prazo do wrapper estourado com a sonda REAL e o filho preso: o grupo todo cai, inclusive o filho', function () {
    $started = microtime(true);

    // Filho com prazo de 60s (a sonda não o mataria) e wrapper com 1s: só o kill por grupo do wrapper alcança o filho.
    expect(function () use (&$run) {
        ompRunProbe(OMP_FORK_PROBE, ['60', 'cold', 'plain', 'hang'], hardStopSeconds: 1.0, run: $run);
    })->toThrow(RuntimeException::class, 'o grupo foi encerrado');

    expect(microtime(true) - $started)->toBeLessThan(6.0);
    ompAssertNoSurvivors($run);
});

/**
 * Processo qualquer que NÃO é da sonda (fica no grupo do Pest): prova que o kill por grupo não alcança quem não é dela.
 *
 * @return resource
 */
function ompBystander()
{
    $process = proc_open(['sleep', '60'], [], $pipes);

    if (! is_resource($process)) {
        test()->fail('não foi possível iniciar o processo testemunha');
    }

    return $process;
}

it('prazo estourado: o wrapper mata o grupo inteiro (sonda e filho), sem tocar em quem não é do grupo', function () {
    $bystander = ompBystander();
    $started   = microtime(true);

    try {
        expect(function () use (&$run) {
            ompRunProbe(OMP_SUPERVISION_FIXTURE, ['hang'], hardStopSeconds: 1.5, run: $run);
        })->toThrow(RuntimeException::class, 'o grupo foi encerrado');

        expect(microtime(true) - $started)->toBeLessThan(6.0);
        ompAssertNoSurvivors($run);
        test()->assertTrue(proc_get_status($bystander)['running'], 'a testemunha fora do grupo foi atingida');
    } finally {
        proc_terminate($bystander);
        proc_close($bystander);
    }
});

it('sonda morta logo depois do fork, antes de gravar qualquer coisa: o filho órfão também é encerrado', function () {
    $bystander = ompBystander();

    try {
        expect(function () use (&$run) {
            ompRunProbe(OMP_SUPERVISION_FIXTURE, ['die-after-fork'], hardStopSeconds: 8, run: $run);
        })->toThrow(RuntimeException::class, 'não devolveu o resultado');

        ompAssertNoSurvivors($run);
        test()->assertTrue(proc_get_status($bystander)['running'], 'a testemunha fora do grupo foi atingida');
    } finally {
        proc_terminate($bystander);
        proc_close($bystander);
    }
});

it('prazo estourado antes de a sonda virar líder de grupo: o wrapper ainda a encerra', function () {
    $started = microtime(true);

    expect(function () use (&$run) {
        ompRunProbe(OMP_SUPERVISION_FIXTURE, ['hang-before-setsid'], hardStopSeconds: 0.5, run: $run);
    })->toThrow(RuntimeException::class, 'o grupo foi encerrado');

    expect(microtime(true) - $started)->toBeLessThan(5.0);
    ompAssertNoSurvivors($run);
});

it('a sonda roda com ambiente mínimo: segredos do processo do teste não chegam a ela', function () {
    $secrets = ['DB_PASSWORD' => 'omp-sentinela-db', 'SENTRY_DSN' => 'omp-sentinela-dsn', 'APP_KEY' => 'omp-sentinela-key'];

    foreach ($secrets as $name => $value) {
        putenv("{$name}={$value}");
    }

    try {
        $names = ompRunProbe(OMP_SUPERVISION_FIXTURE, ['env'], ['OMP_THREAD_LIMIT' => '1'], run: $run)['env'];
    } finally {
        foreach (array_keys($secrets) as $name) {
            putenv($name);
        }
    }

    expect($names)->not->toContain('DB_PASSWORD')
        ->and($names)->not->toContain('SENTRY_DSN')
        ->and($names)->not->toContain('APP_KEY')
        ->and($names)->toContain('OMP_THREAD_LIMIT')
        ->and(array_diff($names, [...OMP_PROBE_ENV_ALLOWLIST, 'OMP_THREAD_LIMIT', 'PWD', 'SHLVL', '_', 'OLDPWD']))->toBe([]);
    ompAssertNoSurvivors($run);
});
