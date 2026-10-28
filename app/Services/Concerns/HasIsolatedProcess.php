<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Execução de processo filho isolado (setsid + kill do grupo + teto de saída
 * + timeout de parede). Extraído de BoundedPdfProcess para servir também ao
 * BoundedVisionProcess (scripts Python do Gerenciador de exames) sem uma
 * terceira cópia. Os códigos de falha saem de dois ganchos, para cada classe
 * manter o seu prefixo (`pdf_*`, `vision_*`).
 */
trait HasIsolatedProcess
{
    public static function hasIsolation(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/setsid')
            && function_exists('posix_kill') && function_exists('posix_setrlimit')
            && defined('POSIX_RLIMIT_AS') && defined('POSIX_RLIMIT_CPU') && defined('POSIX_RLIMIT_FSIZE');
    }

    /** Runs only commands assembled by the application; never accepts request command input. */
    public function execute(array $command, int $maxOutputBytes, float $timeout): string
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $output = '';
        $process->start();
        $pid = $process->getPid();

        try {
            $process->wait(function (string $type, string $bytes) use ($process, &$output, $maxOutputBytes): void {
                if ($type === Process::OUT) {
                    if (strlen($output) + strlen($bytes) > $maxOutputBytes) {
                        throw new RuntimeException($this->outputLimitFailureReason());
                    }
                    $output .= $bytes;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->workerFailureReason());
            }

            return $output;
        } finally {
            // setsid makes the worker its own process group; delegates inherit it.
            if ($pid !== null && function_exists('posix_kill')) {
                @posix_kill(-$pid, SIGKILL);
            }
            $process->stop(0, SIGKILL);
        }
    }

    protected function outputLimitFailureReason(): string
    {
        return 'process_output_limit';
    }

    protected function workerFailureReason(): string
    {
        return 'process_worker_failed';
    }
}
