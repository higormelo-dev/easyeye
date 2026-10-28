<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Abre a planilha de importação guardada no disco padrão (FILESYSTEM_DISK) como handle
 * seekable — o parser usa fseek/rewind (BOM, detecção de delimitador). O
 * disco pode ser S3 (stream não seekable, sem path local), então o conteúdo
 * é copiado para php://temp (memória até 2 MB, depois arquivo temporário).
 *
 * @return resource|null null quando o arquivo não existe no disco
 */
trait OpensImportFile
{
    private function openImportFile(string $filePath): mixed
    {
        $disk = Storage::disk();

        if (! $disk->exists($filePath)) {
            return null;
        }

        $source = $disk->readStream($filePath);

        if (! $source) {
            return null;
        }

        $handle = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'r+');
        stream_copy_to_stream($source, $handle);
        fclose($source);
        rewind($handle);

        return $handle;
    }
}
