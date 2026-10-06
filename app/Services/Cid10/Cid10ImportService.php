<?php

declare(strict_types=1);

namespace App\Services\Cid10;

use App\Enums\ImportStatus;
use App\Models\Cid10Import;
use App\Services\Cid10CatalogImporter;
use Illuminate\Support\Facades\{Log, Storage};
use Illuminate\Support\Str;
use Throwable;

/**
 * Processa uma importação da CID-10 enviada na tela (job
 * ProcessCid10ImportJob): copia os CSVs normalizados da pasta da importação
 * para uma pasta temporária local e roda o Cid10CatalogImporter (a MESMA
 * carga da migration/seeder), gravando o progresso no model — que transmite
 * por WebSocket (BroadcastsImportProgress).
 */
class Cid10ImportService
{
    /** Escrita de progresso no banco: no máximo 2x/s (a transmissão já é limitada no model). */
    private const REPORT_INTERVAL = 0.5;

    public function __construct(
        private readonly Cid10CatalogImporter $importer,
    ) {
    }

    public function run(Cid10Import $import): void
    {
        $import->update([
            'status'         => ImportStatus::Processing,
            'phase'          => Cid10CatalogImporter::PHASE_READING,
            'started_at'     => now(),
            'processed_rows' => 0,
            'error'          => null,
        ]);

        $directory = sys_get_temp_dir() . '/cid10import_' . Str::uuid7();
        $lastAt    = 0.0;
        $lastPhase = null;

        try {
            $this->copyFiles($import, $directory);

            $result = $this->importer->import($directory, function (string $phase, int $processed, int $total) use ($import, &$lastAt, &$lastPhase): void {
                $now = microtime(true);

                if ($phase === $lastPhase && $now - $lastAt < self::REPORT_INTERVAL && $processed < $total) {
                    return;
                }

                $lastAt    = $now;
                $lastPhase = $phase;
                $import->update(['phase' => $phase, 'processed_rows' => $processed, 'total_rows' => $total]);
            });

            $import->update([
                'status'                 => ImportStatus::Done,
                'phase'                  => null,
                'total_rows'             => $result['read'],
                'processed_rows'         => $result['read'],
                'read_count'             => $result['read'],
                'created_count'          => $result['inserted'],
                'corrected_count'        => $result['corrected'],
                'official_updated_count' => $result['official_updated'],
                'kept_edited_count'      => $result['kept_edited'],
                'skipped_invalid'        => $result['skipped'],
                'finished_at'            => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Falha na importação da CID-10', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
                'at'        => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            $import->update([
                'status'      => ImportStatus::Failed,
                'phase'       => null,
                'error'       => $e instanceof Cid10ImportException ? $e->getMessage() : __('manager_cid10.import_failed_generic'),
                'finished_at' => now(),
            ]);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }

    /** Disco padrão (S3 ou local) → pasta temporária local com os nomes que o importador lê. */
    private function copyFiles(Cid10Import $import, string $directory): void
    {
        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new Cid10ImportException(__('manager_cid10.import_failed_generic'));
        }

        foreach ([Cid10ImportFiles::KIND_SUBCATEGORIES, Cid10ImportFiles::KIND_GROUPS, Cid10ImportFiles::KIND_CHAPTERS] as $kind) {
            $path = "{$import->folder}/cid-10-{$kind}.csv";

            if (! Storage::disk()->exists($path)) {
                if ($kind === Cid10ImportFiles::KIND_SUBCATEGORIES) {
                    throw new Cid10ImportException(__('manager_cid10.import_file_missing'));
                }

                continue;
            }

            file_put_contents("{$directory}/cid-10-{$kind}.csv", Storage::disk()->get($path));
        }
    }
}
