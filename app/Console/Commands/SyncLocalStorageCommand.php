<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copia os arquivos que ficaram em storage/app/private (antigo disco
 * `private`/`local`: planilhas e relatórios de importação, anexos de
 * prontuário, XMLs TISS) para o disco padrão — usado uma vez ao ligar
 * FILESYSTEM_DISK=s3, para registros antigos continuarem baixando. Mantém o
 * caminho relativo (o que está gravado no banco, inclusive os antigos
 * "private/tiss/..."), não sobrescreve o que já existe no destino e não
 * apaga nada do disco local.
 */
class SyncLocalStorageCommand extends Command
{
    protected $signature = 'storage:sync-local
                            {--from= : Pasta de origem (padrão: storage/app/private)}
                            {--dry-run : Só lista o que seria copiado}';

    protected $description = 'Copia arquivos de storage/app/private para o disco padrão (FILESYSTEM_DISK, ex.: s3)';

    public function handle(): int
    {
        $default = (string) config('filesystems.default');

        if (config("filesystems.disks.{$default}.driver") === 'local') {
            $this->warn("O disco padrão ({$default}) já é local — nada a sincronizar.");

            return self::SUCCESS;
        }

        $source = Storage::build([
            'driver' => 'local',
            'root'   => $this->option('from') ?: storage_path('app/private'),
        ]);
        $target = Storage::disk();
        $dryRun = (bool) $this->option('dry-run');

        $copied = $skipped = $failed = 0;

        foreach ($source->allFiles() as $path) {
            if (str_ends_with($path, '.gitignore')) {
                continue;
            }

            if ($target->exists($path)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("copiaria: {$path}");
                $copied++;

                continue;
            }

            $stream = $source->readStream($path);
            $ok     = $stream && $target->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($ok) {
                $copied++;
            } else {
                $failed++;
                $this->error("falhou: {$path}");
            }
        }

        $this->info(sprintf(
            '%s: %d copiado(s), %d já existia(m), %d falha(s).',
            $dryRun ? 'Simulação' : 'Concluído',
            $copied,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
