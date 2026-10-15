<?php

use App\Enums\FeatureKey;
use App\Jobs\GenerateExamDerivatives;
use App\Models\{EntityIntegratorEquipment, ExamType, IntegratorCommand, Patient, PatientExam};
use App\Services\UsageMeterService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

it('serializes mixed legacy hardware writes and operational compare-and-swap', function () {
    $ctx       = setupIntegrator();
    $equipment = EntityIntegratorEquipment::factory()->create(['integrator_id' => $ctx['integrator']->id, 'mac' => '00:11:22:33:44:55']);
    $equipment->delete();
    $operation = (string) Str::uuid();
    $results   = p2Concurrent([
        fn () => $this->postJson('/api/integrators/v1/equipments', ['name' => 'LEGACY FINAL', 'mac' => $equipment->mac], $ctx['headers'])->status(),
        fn () => $this->putJson('/api/integrators/v1/equipments/' . $equipment->id, ['name' => 'OPERATIONAL FIRST', 'operation_id' => $operation, 'installation_id' => (string) Str::uuid(), 'expected_config_generation' => 1], [...$ctx['headers'], 'Idempotency-Key' => $operation])->status(),
    ]);
    // A PUT that reaches the row before restore sees no live equipment; after
    // restore it must reject the stale generation. Neither outcome writes.
    expect($results[0])->toBe(200)->and($results[1])->toBeIn([404, 409]);
    expect($equipment->refresh()->name)->toBe('LEGACY FINAL')->and((int) $equipment->config_generation)->toBe(2)->and($equipment->trashed())->toBeFalse();
    expect(DB::table('integrator_equipment_operations')->where('operation_id', $operation)->count())->toBe(0);
});

/**
 * Estado de um filho que não saiu no prazo, lido de /proc (Linux). Na CI a falha dizia só "travou" e não havia como saber
 * onde: dormindo num futex, esperando I/O, em CPU? Cada campo é opcional — o que o kernel não deixar ler vira "?".
 */
function p2ChildState(int $pid): string
{
    $read   = fn (string $file): string => trim((string) @file_get_contents("/proc/{$pid}/{$file}"));
    $status = $read('status');
    preg_match('/^State:\s*(.+)$/m', $status, $state);
    preg_match('/^Threads:\s*(\d+)/m', $status, $threads);
    $links = [];

    foreach (array_slice(array_values(array_diff(@scandir("/proc/{$pid}/fd") ?: [], ['.', '..'])), 0, 12) as $fd) {
        $links[] = $fd . '=' . (string) @readlink("/proc/{$pid}/fd/{$fd}");
    }

    $maps = $read('maps');
    $omp  = p2OpenMpMapping($maps);

    return sprintf(
        '[estado=%s threads=%s wchan=%s syscall=%s libgomp=%s libs_candidatas=%s fds=%s]',
        $state[1] ?? '?',
        $threads[1] ?? '?',
        $read('wchan') ?: '?',
        strtok($read('syscall'), ' ') ?: '?', // só o número da syscall (o resto são argumentos)
        $maps === '' ? '?' : (implode(',', $omp['libgomp']) ?: 'nao-mapeado'), // '?' = mapas ilegíveis, 'nao-mapeado' = lidos e sem libgomp
        $maps === '' ? '?' : (implode(',', $omp['candidatas']) ?: 'nenhuma'),
        implode(' ', $links) ?: '?',
    );
}

/**
 * Onde está o libgomp (OpenMP) e quais outras bibliotecas que costumam trazê-lo ou usá-lo (ImageMagick/Imagick, GD, BLAS)
 * estão mapeadas, a partir do texto de /proc/<pid>/maps. Só caminhos de arquivos .so: nada de environ nem de argumentos.
 * É o que separa "travou no libgomp herdado do pai" de "travou em outra coisa" quando a CI não carrega o Imagick.
 *
 * @return array{libgomp: list<string>, candidatas: list<string>}
 */
function p2OpenMpMapping(string $maps): array
{
    $libgomp    = [];
    $candidates = [];

    foreach (explode("\n", $maps) as $line) {
        if (! preg_match('#\s(/.*?)(?: \(deleted\))?$#', $line, $found)) {
            continue;
        }

        $name = basename($found[1]);

        if (str_contains($name, 'libgomp')) {
            $libgomp[$found[1]] = true;
        } elseif (preg_match('/magick|^gd\.so|^libgd|blas|lapack|^libi?omp/i', $name)) {
            $candidates[$name] = true;
        }
    }

    return ['libgomp' => array_keys($libgomp), 'candidatas' => array_slice(array_keys($candidates), 0, 8)];
}

/**
 * Threads de um processo (linha "Threads:" de /proc/<pid>/status); '?' se o kernel não deixar ler.
 */
function p2ThreadCount(int $pid): string
{
    return preg_match('/^Threads:\s*(\d+)/m', (string) @file_get_contents("/proc/{$pid}/status"), $found) ? $found[1] : '?';
}

/**
 * O que o PROCESSO PAI tinha na hora do fork — o filho herda tudo isso e a CI não guarda nada além da mensagem: threads
 * (pool nativo já criado?), extensões carregadas (há Imagick/GD?) e o OMP_THREAD_LIMIT que o próprio processo enxerga
 * (getenv do PHP; não lê /proc/<pid>/environ).
 */
function p2ParentState(string $threadsAtFork): string
{
    $limit = getenv('OMP_THREAD_LIMIT');

    return sprintf(
        '[pai: threads_no_fork=%s OMP_THREAD_LIMIT=%s extensoes=%s]',
        $threadsAtFork,
        $limit === false ? '(nao definido)' : $limit,
        implode(',', get_loaded_extensions()),
    );
}

/**
 * Filho parado num futex DEPOIS de gravar o resultado é a assinatura de um encerramento nativo travado; se o libgomp está
 * mapeado nele, é a causa conhecida (ForkAfterOpenMpWarmupTest); se não está, a dica não pode acusar OpenMP.
 */
function p2TimeoutHint(string $state): string
{
    if (! str_contains($state, 'wchan=futex') || ! str_contains($state, 'resultado_gravado=sim')) {
        return '';
    }

    if (str_contains($state, 'libgomp=/')) {
        return ' — suspeita: libgomp (OpenMP) herdado pelo fork; rode o Pest com OMP_THREAD_LIMIT=1 no ambiente inicial do PHP e confira libs_candidatas/extensoes acima';
    }

    if (str_contains($state, 'libgomp=nao-mapeado')) {
        return ' — filho parado num futex depois de gravar o resultado, mas o libgomp NÃO está mapeado nele: a causa não é OpenMP; investigue as bibliotecas em libs_candidatas e os módulos em extensoes';
    }

    return ' — filho parado num futex depois de gravar o resultado, mas os mapas de memória não puderam ser lidos (libgomp=?): sem isso não dá para atribuir a causa ao OpenMP';
}

/**
 * Separate processes and PDO connections start together, with no outer test transaction.
 *
 * $waitTimeoutSeconds: só o prazo de ESPERAR O FILHO SAIR (EE-16) — os
 * outros 2 prazos da função (barreira "ready"/"go") ficam fixos em 10s,
 * sem relação com um filho travado depois que o job já começou.
 */
function p2Concurrent(array $jobs, int $waitTimeoutSeconds = 10): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl required');
    }
    $dir = sys_get_temp_dir() . '/p2-concurrency-' . Str::uuid();
    mkdir($dir, 0700);
    DB::disconnect();
    $pids          = [];
    $reaped        = []; // pids que o waitpid já devolveu: o número pode ser de outro processo daqui em diante, nunca mais é sinalizado
    $parentThreads = p2ThreadCount(getmypid()); // antes do 1º fork: é o que cada filho herda

    try {
        foreach ($jobs as $index => $job) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }

            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::connection()->getPdo();
                    file_put_contents($dir . '/' . $index . '.ready', 'ready');
                    $deadline = microtime(true) + 10;

                    while (! is_file($dir . '/go')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('barrier timeout');
                        }
                        usleep(10000);
                    }
                    $result = $job();
                    file_put_contents($dir . '/' . $index . '.json', json_encode(['result' => $result], JSON_THROW_ON_ERROR));
                    DB::disconnect();

                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($dir . '/' . $index . '.json', json_encode(['error' => get_class($error) . ': ' . $error->getMessage()]));

                    exit(1);
                }
            }
            $pids[$index] = $pid;
        }
        $deadline = microtime(true) + 10;

        while (count(glob($dir . '/*.ready')) !== count($jobs)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('ready timeout');
            }
            usleep(10000);
        }
        file_put_contents($dir . '/go', 'go');
        $results = [];

        foreach ($pids as $index => $pid) {
            // EE-16 (auditoria 11/10/2026): pcntl_waitpid() bloqueante sem
            // prazo podia travar a suíte inteira pra sempre se o filho
            // nunca saísse (travado num lock nativo, deadlock, etc.) — a
            // 1ª execução da auditoria ficou presa exatamente aqui. Mesmo
            // padrão de barreira (deadline + WNOHANG + usleep) já usado
            // acima pra "ready"/"go"; filho que não sai dentro do prazo é
            // morto e a falha fica legível, nunca um processo órfão.
            $waitDeadline = microtime(true) + $waitTimeoutSeconds;

            while (true) {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);

                if ($waited === $pid) {
                    $reaped[$pid] = true;

                    break;
                }

                if ($waited === -1) {
                    throw new RuntimeException("pcntl_waitpid failed for pid {$pid} (job {$index})");
                }

                if (microtime(true) > $waitDeadline) {
                    // ANTES de matar: depois do SIGKILL o /proc some. "resultado_gravado" separa travar no job (não) de travar
                    // só ao sair (sim: o filho já escreveu o JSON e o que falta é o encerramento do processo).
                    $state = p2ChildState($pid) . ' ' . p2ParentState($parentThreads) . ' resultado_gravado=' . (is_file($dir . '/' . $index . '.json') ? 'sim' : 'nao');
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status); // SIGKILL nunca é ignorado — bloqueia só até o kernel reaproveitar o pid, não indefinidamente.
                    $reaped[$pid] = true;

                    $hint = p2TimeoutHint($state);

                    throw new RuntimeException("child {$pid} (job {$index}) did not exit within {$waitTimeoutSeconds}s and was killed — check for a hang in the job or a native dependency {$state}{$hint}");
                }

                usleep(10000);
            }

            if (pcntl_wifsignaled($status)) {
                throw new RuntimeException("child {$pid} (job {$index}) was killed by signal " . pcntl_wtermsig($status));
            }

            if (! pcntl_wifexited($status) || ! in_array(pcntl_wexitstatus($status), [0, 1], true)) {
                throw new RuntimeException("child {$pid} (job {$index}) exited with an unexpected status");
            }

            $data = json_decode(file_get_contents($dir . '/' . $index . '.json'), true, flags: JSON_THROW_ON_ERROR);

            if (isset($data['error'])) {
                throw new RuntimeException($data['error']);
            }
            $results[] = $data['result'];
        }

        return $results;
    } finally {
        foreach ($pids as $pid) {
            if (isset($reaped[$pid])) {
                continue; // já reapado: sinalizar agora arriscaria um processo que ganhou o mesmo número
            }

            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status, WNOHANG);
        }

        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
        DB::purge();
    }
}

it('[GAP] filho travado é morto dentro do prazo e produz falha legível, nunca trava a suíte pra sempre — achado via auditoria de encerramento (EE-16)', function () {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl required');
    }
    $start = microtime(true);

    expect(fn () => p2Concurrent([fn () => sleep(999)], waitTimeoutSeconds: 1))
        ->toThrow(RuntimeException::class, 'did not exit within');

    // Prova que o teste não esperou os 999s do job travado — só o prazo
    // curto configurado (1s) + a margem de poll (usleep 10ms). Sem o
    // WNOHANG/deadline (código original), pcntl_waitpid() bloqueante teria
    // travado esta asserção indefinidamente.
    expect(microtime(true) - $start)->toBeLessThan(5.0);
});

it('[GAP] o diagnóstico lê só caminhos de /proc/<pid>/maps: libgomp, bibliotecas candidatas e dica coerente', function () {
    $withOpenMp = implode("\n", [
        '55d0a0000000-55d0a0100000 r--p 00000000 08:01 1234                      /usr/bin/php8.4',
        '7f1c00000000-7f1c00021000 r-xp 00000000 08:01 2001                      /usr/lib/x86_64-linux-gnu/libgomp.so.1.0.0',
        '7f1c10000000-7f1c10021000 r-xp 00000000 08:01 2002                      /usr/lib/x86_64-linux-gnu/libMagickCore-6.Q16.so.7.0.0',
        '7f1c20000000-7f1c20021000 r-xp 00000000 08:01 2003                      /usr/lib/php/20240924/imagick.so',
        '7f1c30000000-7f1c30021000 r-xp 00000000 08:01 2004                      /usr/lib/php/20240924/gd.so',
        '7f1c40000000-7f1c40021000 r-xp 00000000 08:01 2005                      /usr/lib/x86_64-linux-gnu/libc.so.6',
        '7ffd00000000-7ffd00021000 rw-p 00000000 00:00 0                         [stack]',
    ]);
    $mapping = p2OpenMpMapping($withOpenMp);

    expect($mapping['libgomp'])->toBe(['/usr/lib/x86_64-linux-gnu/libgomp.so.1.0.0'])
        ->and($mapping['candidatas'])->toBe(['libMagickCore-6.Q16.so.7.0.0', 'imagick.so', 'gd.so']);

    $without = p2OpenMpMapping("55d0a0000000-55d0a0100000 r--p 00000000 08:01 1234  /usr/bin/php8.4\n7f1c40000000-7f1c40021000 r-xp 00000000 08:01 2005  /usr/lib/x86_64-linux-gnu/libc.so.6");
    expect($without)->toBe(['libgomp' => [], 'candidatas' => []]);

    // A dica só acusa OpenMP quando o libgomp está mesmo mapeado no filho parado.
    $stuck = '[estado=S (sleeping) threads=1 wchan=futex_wait_queue syscall=202 libgomp=%s libs_candidatas=x fds=?] resultado_gravado=sim';
    expect(p2TimeoutHint(sprintf($stuck, '/usr/lib/libgomp.so.1')))->toContain('suspeita: libgomp')
        ->and(p2TimeoutHint(sprintf($stuck, 'nao-mapeado')))->toContain('NÃO está mapeado')->not->toContain('suspeita: libgomp')
        ->and(p2TimeoutHint(sprintf($stuck, '?')))->toContain('não puderam ser lidos')
        ->and(p2TimeoutHint(str_replace('resultado_gravado=sim', 'resultado_gravado=nao', sprintf($stuck, '/usr/lib/libgomp.so.1'))))->toBe('')
        ->and(p2TimeoutHint(str_replace('wchan=futex_wait_queue', 'wchan=0', sprintf($stuck, '/usr/lib/libgomp.so.1'))))->toBe('');
});

it('[GAP] o timeout do filho registra libgomp, bibliotecas, threads do pai no fork, extensões e OMP_THREAD_LIMIT', function () {
    if (! function_exists('pcntl_fork') || ! is_readable('/proc/self/maps')) {
        test()->markTestSkipped('pcntl e /proc necessários');
    }
    $previous = getenv('OMP_THREAD_LIMIT');
    putenv('OMP_THREAD_LIMIT=7'); // o diagnóstico mostra o que o processo enxerga; o libgomp já carregado não é afetado

    try {
        $message = '';

        try {
            p2Concurrent([fn () => sleep(999)], waitTimeoutSeconds: 1);
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }
    } finally {
        putenv($previous === false ? 'OMP_THREAD_LIMIT' : 'OMP_THREAD_LIMIT=' . $previous);
    }

    $expected = p2OpenMpMapping((string) file_get_contents('/proc/self/maps'));

    expect($message)->toContain('did not exit within 1s')
        ->and($message)->toContain('libgomp=' . (implode(',', $expected['libgomp']) ?: 'nao-mapeado'))
        ->and($message)->toContain('libs_candidatas=')
        ->and($message)->toContain('[pai: threads_no_fork=')
        ->and($message)->toContain('OMP_THREAD_LIMIT=7')
        ->and($message)->toContain('extensoes=' . implode(',', get_loaded_extensions()));
});

it('keeps the first terminal ACK across two committed HTTP requests', function () {
    $ctx     = setupIntegrator();
    $command = IntegratorCommand::create(['integrator_id' => $ctx['integrator']->id, 'type' => 'run_diagnostics', 'status' => 'pending', 'payload' => [], 'expires_at' => now()->addMinutes(5)]);
    $results = p2Concurrent(array_map(fn ($pending) => fn () => [
        'status'  => $this->postJson('/api/integrators/v1/commands/' . $command->id . '/ack', ['status' => 'completed', 'result' => ['pending' => $pending]], $ctx['headers'])->status(),
        'pending' => $pending,
    ], [3, 8]));
    expect(array_column($results, 'status'))->toBe([204, 204]);
    $saved = $command->refresh()->result;
    expect($saved['pending'])->toBeIn([3, 8]);
    $this->postJson('/api/integrators/v1/commands/' . $command->id . '/ack', ['status' => 'failed', 'result' => ['error_code' => 'command_failed']], $ctx['headers'])->assertNoContent();
    expect($command->refresh()->result)->toBe($saved)->and($command->status)->toBe('completed');
});

it('commits one durable equipment operation and replays concurrent requests', function () {
    $ctx  = setupIntegrator();
    $id   = (string) Str::uuid();
    $body = ['operation_id' => $id, 'installation_id' => (string) Str::uuid(), 'name' => 'Synthetic OCULUS'];
    $job  = function () use ($body, $ctx, $id) {
        $r = $this->postJson('/api/integrators/v1/equipments', $body, $ctx['headers'] + ['Idempotency-Key' => $id]);

        return ['status' => $r->status(), 'body' => $r->json()];
    };
    $results = p2Concurrent([$job, $job]);

    foreach ($results as $result) {
        expect($result['status'])->toBeIn([201, 409]);

        if ($result['status'] === 409) {
            expect($result['body']['code'])->toBe('equipment_operation_in_progress');
        }
    }
    $replay = $this->postJson('/api/integrators/v1/equipments', $body, $ctx['headers'] + ['Idempotency-Key' => $id])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    foreach ($results as $result) {
        if ($result['status'] === 201) {
            expect($result['body'])->toBe($replay->json());
        }
    }
    expect(EntityIntegratorEquipment::count())->toBe(1)->and(DB::table('integrator_equipment_operations')->count())->toBe(1);
});

it('allocates unique scoped EXM numbers for concurrent producers', function () {
    $ctx     = setupIntegrator();
    $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
    $job     = fn () => PatientExam::factory()->create(['patient_id' => $patient->id])->code;
    $codes   = p2Concurrent([$job, $job]);
    sort($codes);
    expect($codes)->toBe(['EXM-0000000001', 'EXM-0000000002'])->and(PatientExam::where('patient_id', $patient->id)->count())->toBe(2);
});

it('reserves only one final monthly quota slot across concurrent committed HTTP uploads', function () {
    Storage::fake('s3');
    $ctx     = setupIntegrator([FeatureKey::ApiMonthlyExamSends->value => '1']);
    $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id, 'active' => true]);
    $type    = ExamType::factory()->create(['entity_id' => null]);
    $file    = UploadedFile::fake()->image('synthetic-concurrent.jpg');
    $job     = function () use ($ctx, $patient, $type) {
        $file = UploadedFile::fake()->image('synthetic-concurrent.jpg');
        $id   = (string) Str::uuid();
        $r    = $this->postJson('/api/integrators/v1/exams', ['exam_identifier' => $type->id, 'patient_identifier' => $patient->id, 'archive' => $file, 'name' => 'Synthetic exam', 'capture_id' => $id, 'content_sha256' => hash_file('sha256', $file->getRealPath())], $ctx['headers'] + ['Idempotency-Key' => $id]);

        return ['status' => $r->status(), 'body' => $r->json()];
    };
    $results  = p2Concurrent([$job, $job]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([201, 403])->and(PatientExam::count())->toBe(1)
        ->and(DB::table('integrator_api_receipts')->count())->toBe(1)
        ->and((int) DB::table('feature_usages')->where('entity_id', $ctx['entity']->id)->where('feature', FeatureKey::ApiMonthlyExamSends->value)->value('used'))->toBe(1);
});

it('rolls back the reserved period even when the clock crosses a month boundary', function () {
    $ctx          = setupIntegrator();
    $subscription = $ctx['entity']->subscriptions()->latest()->first();

    try {
        $this->travelTo(now()->startOfMonth()->endOfMonth()->setTime(23, 59, 59));
        $old = now()->format('Y-m');
        expect(fn () => DB::transaction(function () use ($ctx, $subscription) {
            expect(app(UsageMeterService::class)->reserveMonthly($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends, $subscription->id, 1))->toBeTrue();
            $this->travel(2)->seconds();

            throw new RuntimeException('synthetic rollback');
        }))->toThrow(RuntimeException::class);
        expect(DB::table('feature_usages')->where('entity_id', $ctx['entity']->id)->where('period', $old)->count())->toBe(0);
        expect(app(UsageMeterService::class)->reserveMonthly($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends, $subscription->id, 1))->toBeTrue()
            ->and(DB::table('feature_usages')->where('entity_id', $ctx['entity']->id)->value('period'))->not->toBe($old);
    } finally {
        $this->travelBack();
    }
});

it('does not mark a concurrently replaced original failed for a legacy null-expected derivative job', function () {
    Storage::fake('s3');
    $ctx      = setupIntegrator();
    $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
    $exam     = PatientExam::factory()->create(['patient_id' => $patient->id, 'archive' => 'old.png']);
    $config   = DB::connection()->getConfig();
    $observer = new PDO('pgsql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'], $config['username'], $config['password']);
    $disk     = Mockery::mock(Storage::disk('s3'))->makePartial();
    $disk->shouldReceive('readStream')->andReturnUsing(function () use ($observer, $exam) {
        $query = $observer->prepare('UPDATE patient_exams SET archive = ?, derivative_status = ? WHERE id = ?');
        $query->execute(['replacement.png', 'pending', $exam->id]);

        throw new RuntimeException('synthetic old-archive failure');
    });
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    expect(fn () => (new GenerateExamDerivatives($exam->id))->handle())->toThrow(RuntimeException::class);
    expect($exam->refresh()->archive)->toBe('replacement.png')->and($exam->derivative_status)->toBe('pending');
});
