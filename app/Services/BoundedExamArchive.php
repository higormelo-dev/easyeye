<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BoundedExamArchive
{
    public function read(string $path, int $limit = 10485760): string
    {
        $stream = Storage::disk('s3')->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('archive_unavailable');
        }

        try {
            stream_set_timeout($stream, 10);
            $raw        = '';
            $started    = hrtime(true);
            $emptyReads = 0;

            while (! feof($stream)) {
                if ((hrtime(true) - $started) / 1e9 > 15) {
                    throw new RuntimeException('archive_read_timeout');
                }
                $chunk = fread($stream, min(65536, $limit - strlen($raw) + 1));
                $meta  = stream_get_meta_data($stream);

                if ($chunk === false || ($meta['timed_out'] ?? false)) {
                    throw new RuntimeException('archive_read_failed');
                }

                if ($chunk === '' && ! feof($stream) && ++$emptyReads > 2) {
                    throw new RuntimeException('archive_read_stalled');
                }

                if ($chunk !== '') {
                    $emptyReads = 0;
                }
                $raw .= $chunk;

                if (strlen($raw) > $limit) {
                    throw new RuntimeException('archive_size_limit');
                }
            }

            return $raw;
        } finally {
            fclose($stream);
        }
    }

    public function putVerified(string $path, string $bytes): void
    {
        if (! Storage::disk('s3')->put($path, $bytes, ['visibility' => 'private']) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $this->read($path, max(strlen($bytes), 1))))) {
            throw new RuntimeException('derivative_storage_failed');
        }
    }
}
