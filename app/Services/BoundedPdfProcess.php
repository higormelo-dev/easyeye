<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class BoundedPdfProcess
{
    public function run(string $mode, string $path): string
    {
        if (! in_array($mode, ['text', 'render'], true)) {
            throw new InvalidArgumentException('pdf_mode_invalid');
        }

        if (! self::hasIsolation()) {
            throw new RuntimeException('pdf_process_isolation_unavailable');
        }

        return $this->execute([
            '/usr/bin/setsid', '--', PHP_BINARY, '-d', 'memory_limit=128M',
            base_path('scripts/exam-pdf-worker.php'), $mode, $path,
        ], $mode === 'text' ? 262144 : 10485760, $mode === 'text' ? 10 : 15);
    }

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
                        throw new RuntimeException('pdf_output_limit');
                    }
                    $output .= $bytes;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException('pdf_worker_failed');
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
}
