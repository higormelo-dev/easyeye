<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Services\Concerns\HasIsolatedProcess;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Processo isolado dos scripts Python de visão (panorama e auto-alinhamento):
 * `setsid -- prlimit … -- python3 -I <script> …`. Os rlimits vão no pai
 * (prlimit) porque não controlamos o import do cv2; o comando é montado só
 * com argumentos internos — caminhos de arquivos temporários e constantes —,
 * nunca com texto vindo do request.
 *
 * Os scripts saem sempre com 0 e devolvem `{"ok":false,"reason":…}` nas falhas
 * tratadas; exit ≠ 0 é crash real (vision_worker_failed). O `reason` é um
 * código interno: os controllers o traduzem e nunca o devolvem ao cliente.
 *
 * Não é `final` de propósito: os testes trocam a classe por um mock no
 * container (stitch/align) para não depender de cv2.
 */
class BoundedVisionProcess
{
    use HasIsolatedProcess;

    public const string PYTHON = 'python3';

    public const string PRLIMIT = '/usr/bin/prlimit';

    public const string ENV = '/usr/bin/env';

    /**
     * Sem estas variáveis o cv2 cai (SIGSEGV/abort) sob `prlimit --as`: o
     * glibc reserva uma arena de 64 MB por thread e o BLAS uma por CPU, e tudo
     * conta no espaço de endereçamento. MALLOC_ARENA_MAX só vale se definida
     * antes do processo iniciar — por isso vão no comando, não no script.
     */
    public const array WORKER_ENV = ['MALLOC_ARENA_MAX=2', 'OPENBLAS_NUM_THREADS=1', 'OMP_NUM_THREADS=1'];

    /** Parede (Process::setTimeout), em segundos. */
    public const float PANORAMA_TIMEOUT = 20.0;

    public const float ALIGN_TIMEOUT = 10.0;

    /** prlimit --cpu: folga sobre o timeout de parede. */
    public const int PANORAMA_CPU_SECONDS = 25;

    public const int ALIGN_CPU_SECONDS = 15;

    /**
     * prlimit --as (espaço de endereçamento, não RSS). Medido no smoke com
     * imagens sintéticas de 2560 px: o stitch de 6 imagens falha a ≤ 900 MB
     * (pico real ~420 MB de RSS) e o align cabe em 640 MB.
     */
    public const int PANORAMA_AS_BYTES = 1610612736; // 1,5 GB

    public const int ALIGN_AS_BYTES = 805306368; // 768 MB

    /** prlimit --fsize: o maior arquivo que o script grava (PNG do panorama): 64 MB. */
    public const int RLIMIT_FSIZE_BYTES = 67108864;

    /** stdout do script é uma linha de JSON. */
    public const int MAX_OUTPUT_BYTES = 8192;

    /** Teto por imagem de entrada baixada do storage: 16 MB. */
    public const int MAX_IMAGE_BYTES = 16777216;

    public const int MAX_PANORAMA_INPUTS = 6;

    /**
     * Teto GLOBAL de processos Python simultâneos (todas as clínicas, todos os
     * workers do FPM): cada um chega a 1,5 GB de endereçamento e vários núcleos.
     * Slots são locks de cache — com mais de um servidor, o CACHE_STORE precisa
     * ser compartilhado (redis/database) para o teto valer entre eles.
     */
    public const int MAX_CONCURRENT = 2;

    /** O lock do slot vive o timeout de parede do script + esta folga (crash não prende o slot). */
    public const int SLOT_TTL_MARGIN_SECONDS = 5;

    /** Reasons dos scripts Python que podem ir a log/resposta (os `vision_*` e `archive_*` entram por prefixo). */
    public const array SCRIPT_REASONS = [
        'cv2_unavailable', 'usage', 'write_failed', 'no_matches', 'low_confidence', 'stitch_failed', 'unreadable_image',
    ];

    /**
     * O servidor sabe rodar o processo isolado? É o que decide se a UI oferece
     * Panorama/Auto-alinhar. A presença do cv2 NÃO é checada por request: sem
     * ele o script devolve `cv2_unavailable` e o endpoint responde 422.
     * Instância (não estático) para os testes poderem trocá-la por mock.
     */
    public function isAvailable(): bool
    {
        return self::hasIsolation() && is_executable(self::PRLIMIT);
    }

    /**
     * Costura 2..N imagens e grava o PNG em $outPath.
     *
     * @param list<string> $imagePaths caminhos temporários, na ordem da seleção
     *
     * @throws RuntimeException com o código interno do motivo
     */
    public function stitch(array $imagePaths, string $outPath): void
    {
        $this->expectOk($this->runScript(
            'scripts/exam-panorama-stitch.py',
            [$outPath, ...$imagePaths],
            self::PANORAMA_TIMEOUT,
            self::PANORAMA_CPU_SECONDS,
            self::PANORAMA_AS_BYTES,
        ));
    }

    /**
     * Translação que leva a imagem B sobre a A, em px do tamanho natural de A.
     *
     * @return array{dx: float, dy: float, confidence: float}
     *
     * @throws RuntimeException com o código interno do motivo
     */
    public function align(string $imageA, string $imageB): array
    {
        $result = $this->expectOk($this->runScript(
            'scripts/exam-align-detect.py',
            [$imageA, $imageB],
            self::ALIGN_TIMEOUT,
            self::ALIGN_CPU_SECONDS,
            self::ALIGN_AS_BYTES,
        ));

        foreach (['dx', 'dy', 'confidence'] as $key) {
            if (! isset($result[$key]) || ! is_numeric($result[$key])) {
                throw new RuntimeException('vision_invalid_output');
            }
        }

        return [
            'dx'         => (float) $result['dx'],
            'dy'         => (float) $result['dy'],
            'confidence' => (float) $result['confidence'],
        ];
    }

    /**
     * Código do motivo que PODE ir a log: corta o detalhe depois de ":" (o
     * script cita o caminho do arquivo temporário em `unreadable_image:<path>`)
     * e só deixa passar códigos conhecidos — `vision_*`, `archive_*` e os
     * reasons dos scripts. Qualquer outra mensagem (ex.: a exceção do Symfony
     * que carrega o argv inteiro, com caminhos) vira `vision_worker_failed`.
     */
    public static function reasonCode(string $reason): string
    {
        $colon = strpos($reason, ':');
        $code  = $colon === false ? $reason : substr($reason, 0, $colon);

        if (in_array($code, self::SCRIPT_REASONS, true) || preg_match('/^(vision|archive)_[a-z_]+$/', $code) === 1) {
            return $code;
        }

        return 'vision_worker_failed';
    }

    protected function outputLimitFailureReason(): string
    {
        return 'vision_output_limit';
    }

    protected function workerFailureReason(): string
    {
        return 'vision_worker_failed';
    }

    /**
     * @param list<string> $args argumentos internos (caminhos temporários)
     *
     * @return array<string, mixed>
     */
    private function runScript(string $script, array $args, float $timeout, int $cpuSeconds, int $asBytes): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('vision_process_isolation_unavailable');
        }

        try {
            $slot = $this->acquireSlot($timeout);

            try {
                $output = $this->execute([
                    '/usr/bin/setsid', '--', self::PRLIMIT,
                    '--as=' . $asBytes,
                    '--cpu=' . $cpuSeconds,
                    '--fsize=' . self::RLIMIT_FSIZE_BYTES,
                    '--', self::ENV, ...self::WORKER_ENV,
                    (string) config('eye_image_capture.vision_python', self::PYTHON), '-I', base_path($script), ...$args,
                ], self::MAX_OUTPUT_BYTES, $timeout);
            } finally {
                $slot->release();
            }
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('vision_timeout');
        } catch (Throwable $e) {
            // Só passa adiante o que já é um código nosso, sem detalhe: a
            // exceção do Symfony (argv e caminhos na mensagem), erro de cache
            // etc. viram o código genérico.
            throw $e instanceof RuntimeException && self::reasonCode($e->getMessage()) === $e->getMessage()
                ? $e
                : new RuntimeException('vision_worker_failed');
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            throw new RuntimeException('vision_invalid_output');
        }

        return $decoded;
    }

    /**
     * Ocupa um dos MAX_CONCURRENT slots sem esperar; todos ocupados → vision_busy.
     *
     * @throws RuntimeException vision_busy
     */
    private function acquireSlot(float $timeout): Lock
    {
        for ($slot = 0; $slot < self::MAX_CONCURRENT; $slot++) {
            $lock = Cache::lock('vision-slot:' . $slot, (int) ceil($timeout) + self::SLOT_TTL_MARGIN_SECONDS);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new RuntimeException('vision_busy');
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function expectOk(array $result): array
    {
        if ($result['ok'] !== true) {
            $reason = $result['reason'] ?? null;

            throw new RuntimeException(is_string($reason) && $reason !== '' ? $reason : 'vision_invalid_output');
        }

        return $result;
    }
}
