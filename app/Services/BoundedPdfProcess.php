<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Concerns\HasIsolatedProcess;
use InvalidArgumentException;
use RuntimeException;

final class BoundedPdfProcess
{
    use HasIsolatedProcess;

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

    protected function outputLimitFailureReason(): string
    {
        return 'pdf_output_limit';
    }

    protected function workerFailureReason(): string
    {
        return 'pdf_worker_failed';
    }
}
