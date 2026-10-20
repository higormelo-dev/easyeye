<?php

declare(strict_types=1);

use App\Services\BoundedPdfProcess;
use App\Services\Concerns\HasIsolatedProcess;
use App\Services\EyeImages\BoundedVisionProcess;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Symfony\Component\Process\Exception\{ProcessStartFailedException, ProcessTimedOutException};
use Symfony\Component\Process\Process;

/**
 * BoundedVisionProcess + trait HasIsolatedProcess: o comando que o PHP monta
 * (só argumentos internos), a tradução do JSON do script em exceções com
 * `reason`, e os códigos de falha do trait por classe (pdf_* inalterados,
 * vision_* novos). Sem cv2: o `execute()` é trocado por mock quando o ponto é
 * o comando/JSON, e roda de verdade (php -r) quando o ponto são os códigos.
 */
function visionPartial(): MockInterface
{
    $process = Mockery::mock(BoundedVisionProcess::class)->makePartial();
    $process->shouldReceive('isAvailable')->andReturn(true);

    return $process;
}

describe('comando montado', function () {
    it('panorama: setsid + prlimit + env + python isolado, com os limites do panorama e só os caminhos recebidos', function () {
        $captured = null;
        $process  = visionPartial();
        $process->shouldReceive('execute')->once()->andReturnUsing(function (array $command, int $max, float $timeout) use (&$captured) {
            $captured = [$command, $max, $timeout];

            return '{"ok": true}';
        });

        // Nomes com espaço e metacaracteres de shell seguem como UM argumento.
        $process->stitch(['/tmp/vision-a b', '/tmp/vision-c;rm -rf x'], '/tmp/panorama-out');

        expect($captured)->toBe([[
            '/usr/bin/setsid', '--', '/usr/bin/prlimit',
            '--as=1610612736', '--cpu=25', '--fsize=67108864', '--',
            '/usr/bin/env', 'MALLOC_ARENA_MAX=2', 'OPENBLAS_NUM_THREADS=1', 'OMP_NUM_THREADS=1',
            'python3', '-I', base_path('scripts/exam-panorama-stitch.py'),
            '/tmp/panorama-out', '/tmp/vision-a b', '/tmp/vision-c;rm -rf x',
        ], 8192, 20.0]);
    });

    it('align: mesmo molde, com os limites do align e A antes de B', function () {
        $captured = null;
        $process  = visionPartial();
        $process->shouldReceive('execute')->once()->andReturnUsing(function (array $command, int $max, float $timeout) use (&$captured) {
            $captured = [$command, $max, $timeout];

            return '{"ok": true, "dx": 1.5, "dy": -2, "confidence": 0.9}';
        });

        $result = $process->align('/tmp/vision-a', '/tmp/vision-b');

        expect($captured)->toBe([[
            '/usr/bin/setsid', '--', '/usr/bin/prlimit',
            '--as=805306368', '--cpu=15', '--fsize=67108864', '--',
            '/usr/bin/env', 'MALLOC_ARENA_MAX=2', 'OPENBLAS_NUM_THREADS=1', 'OMP_NUM_THREADS=1',
            'python3', '-I', base_path('scripts/exam-align-detect.py'),
            '/tmp/vision-a', '/tmp/vision-b',
        ], 8192, 10.0])
            ->and($result)->toBe(['dx' => 1.5, 'dy' => -2.0, 'confidence' => 0.9]);
    });

    it('os scripts existem onde o comando aponta', function () {
        expect(base_path('scripts/exam-panorama-stitch.py'))->toBeFile()
            ->and(base_path('scripts/exam-align-detect.py'))->toBeFile();
    });
});

describe('JSON do script → exceção com reason', function () {
    it('ok:false vira RuntimeException com o reason do script', function (string $script, string $reason) {
        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andReturn(json_encode(['ok' => false, 'reason' => $reason]));

        $call = $script === 'stitch'
            ? fn () => $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out')
            : fn () => $process->align('/tmp/a', '/tmp/b');

        expect($call)->toThrow(RuntimeException::class, $reason);
    })->with([
        'stitch sem overlap'    => ['stitch', 'stitch_failed:1'],
        'stitch cv2 ausente'    => ['stitch', 'cv2_unavailable'],
        'align sem pontos'      => ['align', 'no_matches'],
        'align baixa confiança' => ['align', 'low_confidence'],
        'align imagem ilegível' => ['align', 'unreadable_image:/tmp/a'],
    ]);

    it('saída que não é o JSON esperado vira vision_invalid_output', function (string $stdout) {
        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andReturn($stdout);

        expect(fn () => $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out'))->toThrow(RuntimeException::class, 'vision_invalid_output');
    })->with([
        'vazia'            => [''],
        'texto'            => ['Traceback (most recent call last)'],
        'json sem ok'      => ['{"dx": 1}'],
        'json escalar'     => ['42'],
        'ok não booleano'  => ['{"ok": "true", "dx": 1, "dy": 1, "confidence": 1}'],
        'ok nulo'          => ['{"ok": null}'],
        'falha sem reason' => ['{"ok": false}'],
        'reason vazio'     => ['{"ok": false, "reason": ""}'],
        'reason não texto' => ['{"ok": false, "reason": 7}'],
    ]);

    it('align sem dx/dy/confidence numéricos vira vision_invalid_output', function (string $stdout) {
        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andReturn($stdout);

        expect(fn () => $process->align('/tmp/a', '/tmp/b'))->toThrow(RuntimeException::class, 'vision_invalid_output');
    })->with([
        'sem dx'         => ['{"ok": true, "dy": 1, "confidence": 0.5}'],
        'sem dy'         => ['{"ok": true, "dx": 1, "confidence": 0.5}'],
        'sem confidence' => ['{"ok": true, "dx": 1, "dy": 1}'],
        'dx texto'       => ['{"ok": true, "dx": "x", "dy": 1, "confidence": 0.5}'],
        'tudo nulo'      => ['{"ok": true, "dx": null, "dy": null, "confidence": null}'],
    ]);

    it('timeout de parede vira vision_timeout', function () {
        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andThrow(new ProcessTimedOutException(new Process(['x']), ProcessTimedOutException::TYPE_GENERAL));

        expect(fn () => $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out'))->toThrow(RuntimeException::class, 'vision_timeout');
    });

    it('sem isolamento no servidor: vision_process_isolation_unavailable e nada é executado', function () {
        $process = Mockery::mock(BoundedVisionProcess::class)->makePartial();
        $process->shouldReceive('isAvailable')->andReturn(false);
        $process->shouldNotReceive('execute');

        expect(fn () => $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out'))->toThrow(RuntimeException::class, 'vision_process_isolation_unavailable')
            ->and(fn () => $process->align('/tmp/a', '/tmp/b'))->toThrow(RuntimeException::class, 'vision_process_isolation_unavailable');
    });
});

describe('reasonCode (o que pode ir a log)', function () {
    it('corta o detalhe depois de ":" e deixa passar só códigos conhecidos', function (string $reason, string $code) {
        expect(BoundedVisionProcess::reasonCode($reason))->toBe($code);
    })->with([
        'caminho'         => ['unreadable_image:/tmp/vision-abc', 'unreadable_image'],
        'status'          => ['stitch_failed:2', 'stitch_failed'],
        'sem ":"'         => ['vision_timeout', 'vision_timeout'],
        'ocupado'         => ['vision_busy', 'vision_busy'],
        'isolamento'      => ['vision_process_isolation_unavailable', 'vision_process_isolation_unavailable'],
        'archive'         => ['archive_size_limit', 'archive_size_limit'],
        'cv2'             => ['cv2_unavailable', 'cv2_unavailable'],
        'uso'             => ['usage', 'usage'],
        'gravação'        => ['write_failed', 'write_failed'],
        'sem pontos'      => ['no_matches', 'no_matches'],
        'baixa confiança' => ['low_confidence', 'low_confidence'],
    ]);

    it('qualquer outra mensagem vira vision_worker_failed (nunca o texto da exceção)', function (string $reason) {
        expect(BoundedVisionProcess::reasonCode($reason))->toBe('vision_worker_failed');
    })->with([
        'argv do symfony'     => ['The command "/usr/bin/setsid \'--\' \'/usr/bin/prlimit\' \'/tmp/vision-secret\'" failed.'],
        'caminho solto'       => ['/tmp/vision-secret'],
        'vision_ com detalhe' => ['vision_x /tmp/vision-secret'],
        'archive_ maiúsculo'  => ['Archive_unavailable'],
        'vazio'               => [''],
        'código desconhecido' => ['panorama_failed'],
        'prefixo colado'      => ['xvision_timeout'],
    ]);
});

describe('exceções do processo', function () {
    it('exceção que carrega argv e caminhos (ProcessStartFailedException) vira vision_worker_failed, sem o texto', function () {
        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andThrow(
            new ProcessStartFailedException(new Process(['/usr/bin/setsid', '/tmp/vision-secret']), 'Cannot start /tmp/vision-secret'),
        );

        try {
            $process->stitch(['/tmp/vision-secret'], '/tmp/panorama-secret');
            $this->fail('devia lançar');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('vision_worker_failed')
                ->and($e->getPrevious())->toBeNull();
        }
    });

    it('qualquer Throwable de infraestrutura (cache, I/O) também vira vision_worker_failed', function (Throwable $failure) {
        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andThrow($failure);

        expect(fn () => $process->align('/tmp/a', '/tmp/b'))->toThrow(RuntimeException::class, 'vision_worker_failed');
    })->with([
        'Exception comum' => [fn () => new Exception('redis://secret@host /tmp/vision-secret')],
        'Error'           => [fn () => new TypeError('/tmp/vision-secret')],
    ]);

    it('os códigos do próprio processo passam intactos', function (string $reason) {
        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andThrow(new RuntimeException($reason));

        expect(fn () => $process->align('/tmp/a', '/tmp/b'))->toThrow(RuntimeException::class, $reason);
    })->with(['vision_output_limit', 'vision_worker_failed']);
});

describe('teto global de processos simultâneos', function () {
    function holdVisionSlots(int $count): array
    {
        $locks = [];

        for ($slot = 0; $slot < $count; $slot++) {
            $lock = Cache::lock('vision-slot:' . $slot, 60);
            expect($lock->get())->toBeTrue();
            $locks[] = $lock;
        }

        return $locks;
    }

    it('com os 2 slots ocupados: vision_busy e o script nem roda', function () {
        holdVisionSlots(2);
        $process = visionPartial();
        $process->shouldNotReceive('execute');

        expect(fn () => $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out'))->toThrow(RuntimeException::class, 'vision_busy')
            ->and(fn () => $process->align('/tmp/a', '/tmp/b'))->toThrow(RuntimeException::class, 'vision_busy');
    });

    it('com 1 slot livre (qualquer um dos dois) roda normalmente', function (int $busy) {
        $held = Cache::lock('vision-slot:' . $busy, 60);
        expect($held->get())->toBeTrue();

        $process = visionPartial();
        $process->shouldReceive('execute')->once()->andReturn('{"ok": true}');

        $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out');
    })->with([0, 1]);

    it('o slot é do processo enquanto ele roda e volta ao terminar, no sucesso e na falha', function (bool $fails) {
        $process = visionPartial();
        $inside  = null;

        $process->shouldReceive('execute')->once()->andReturnUsing(function () use (&$inside, $fails) {
            // Durante a execução, 1 slot está ocupado: só sobra um.
            $first  = Cache::lock('vision-slot:0', 5);
            $second = Cache::lock('vision-slot:1', 5);
            $inside = [$first->get(), $second->get()];
            array_map(fn ($lock) => $lock->release(), [$first, $second]);

            if ($fails) {
                throw new RuntimeException('vision_worker_failed');
            }

            return '{"ok": true}';
        });

        try {
            $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out');
        } catch (RuntimeException) {
        }

        // um dos dois estava ocupado pelo processo (o de índice 0), o outro livre
        expect($inside)->toBe([false, true]);

        foreach ([0, 1] as $slot) {
            $lock = Cache::lock('vision-slot:' . $slot, 5);
            expect($lock->get())->toBeTrue();
            $lock->release();
        }
    })->with([false, true]);

    it('o lock expira com o timeout de parede + 5 s (crash não prende o slot)', function (string $method, int $ttl) {
        $process   = visionPartial();
        $stillHeld = $expired = null;

        $process->shouldReceive('execute')->once()->andReturnUsing(function () use (&$stillHeld, &$expired, $ttl) {
            // Só o slot 0 é do processo: prende o 1 e olha o 0 antes e depois do TTL.
            $other = Cache::lock('vision-slot:1', 120);
            $other->get();

            $this->travel($ttl - 1)->seconds();
            $stillHeld = ! Cache::lock('vision-slot:0', 1)->get();

            $this->travel(2)->seconds();
            $expired = Cache::lock('vision-slot:0', 1)->get();

            return '{"ok": true, "dx": 0, "dy": 0, "confidence": 1}';
        });

        $method === 'stitch'
            ? $process->stitch(['/tmp/a', '/tmp/b'], '/tmp/out')
            : $process->align('/tmp/a', '/tmp/b');

        expect($stillHeld)->toBeTrue()->and($expired)->toBeTrue();
    })->with([
        'panorama (20 s + 5)' => ['stitch', 25],
        'align (10 s + 5)'    => ['align', 15],
    ]);
});

describe('códigos de falha do trait HasIsolatedProcess', function () {
    beforeEach(function () {
        if (! BoundedVisionProcess::hasIsolation()) {
            $this->markTestSkipped('Linux process isolation required');
        }
    });

    it('cada classe usa o seu prefixo: pdf_* (inalterado) e vision_*', function (object $process, string $worker, string $output) {
        expect(fn () => $process->execute([PHP_BINARY, '-r', 'exit(3);'], 1024, 10.0))->toThrow(RuntimeException::class, $worker)
            ->and(fn () => $process->execute([PHP_BINARY, '-r', 'echo str_repeat("x", 100);'], 10, 10.0))->toThrow(RuntimeException::class, $output);
    })->with([
        'pdf'    => [fn () => new BoundedPdfProcess(), 'pdf_worker_failed', 'pdf_output_limit'],
        'vision' => [fn () => new BoundedVisionProcess(), 'vision_worker_failed', 'vision_output_limit'],
        'trait'  => [fn () => new class() {
            use HasIsolatedProcess;
        }, 'process_worker_failed', 'process_output_limit'],
    ]);

    it('devolve o stdout quando o processo termina bem e dentro do teto', function () {
        $process = new BoundedVisionProcess();

        expect($process->execute([PHP_BINARY, '-r', 'echo "{\"ok\":true}";'], 1024, 10.0))->toBe('{"ok":true}');
    });

    it('timeout de parede lança ProcessTimedOutException e mata o processo', function () {
        expect(fn () => (new BoundedVisionProcess())->execute([PHP_BINARY, '-r', 'sleep(30);'], 1024, 0.3))
            ->toThrow(ProcessTimedOutException::class);
    });
});
