<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\PatientImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Log, Storage};
use Throwable;

/**
 * Retenção LGPD das importações de pacientes: o CSV original e o CSV de erros têm dado de paciente (nome, CPF, telefone,
 * carteirinha) e só existem para o usuário baixar o relatório e reprocessar. Vencido o prazo (`files_expire_at`, 30 dias
 * por padrão — config patient_imports.retention_days), apaga os dois arquivos e MANTÉM o registro do histórico, com
 * `files_purged_at`. Prévia nunca confirmada e abandonada some inteira (como o cancelamento: não há job). Importação em
 * andamento (`processing`) ou já na fila nunca é tocada.
 *
 * `--days=N` também apaga o que terminou há mais de N dias, mesmo com o prazo renovado (limpeza manual mais curta).
 * Só loga contagens agregadas e o id do import — nunca nome de arquivo, convênio ou paciente.
 *
 * O caminho vem do banco; um registro legado/corrompido não pode fazer o comando apagar outro arquivo do disco privado.
 * Só apaga caminhos que começam EXATAMENTE por `imports/patients/{entity_id}/` do próprio import (sem `..`); o registro
 * com qualquer caminho fora disso é IGNORADO por inteiro (nada dele é apagado nem marcado) e contado na saída.
 *
 * Schedule: diário, 03:55.
 */
class PrunePatientImportFilesCommand extends Command
{
    protected $signature = 'imports:prune-patient-files
        {--days= : Também apaga os arquivos de importações que terminaram há mais de N dias}';

    protected $description = 'Apaga o CSV original e o de erros das importações de pacientes vencidas (LGPD), mantendo o histórico';

    /** Importações ignoradas por caminho fora de imports/patients/{entity_id}/ (nesta execução). */
    private int $unsafe = 0;

    public function handle(): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $this->unsafe = 0;
        $purged       = $this->purgeFinished($days);
        $discarded    = $this->discardAbandonedPreviews();

        Log::info('patient_imports.files_pruned', ['purged' => $purged, 'discarded_previews' => $discarded, 'skipped_unsafe_paths' => $this->unsafe]);
        $this->info("Importações de pacientes: {$purged} com arquivos apagados, {$discarded} prévias abandonadas descartadas, {$this->unsafe} ignorados (caminho fora de imports/patients/{clínica}/).");

        return self::SUCCESS;
    }

    /** Terminadas (done/failed/cancelled) com o prazo vencido: apaga os arquivos e marca files_purged_at. */
    private function purgeFinished(?int $days): int
    {
        $purged = 0;

        PatientImport::withoutGlobalScopes()
            ->whereNull('files_purged_at')
            ->whereIn('status', [ImportStatus::Done->value, ImportStatus::Failed->value, ImportStatus::Cancelled->value])
            ->where(function ($query) use ($days): void {
                $query->where('files_expire_at', '<=', now());

                if ($days !== null) {
                    $query->orWhereRaw('COALESCE(finished_at, created_at) <= ?', [now()->subDays($days)]);
                }
            })
            ->chunkById(200, function ($imports) use (&$purged): void {
                foreach ($imports as $import) {
                    if ($this->deleteFiles($import)) {
                        // Sem eventos: não há o que transmitir à tela de importações por causa da limpeza.
                        $import->forceFill(['errors_file_path' => null, 'files_purged_at' => now()])->saveQuietly();
                        $purged++;
                    }
                }
            });

        return $purged;
    }

    /** Prévia que ninguém confirmou (sem job) e passou do prazo: apaga o arquivo e o registro. */
    private function discardAbandonedPreviews(): int
    {
        $discarded = 0;

        PatientImport::withoutGlobalScopes()
            ->where('status', ImportStatus::Pending->value)
            ->whereNull('confirmed_at')
            ->where('files_expire_at', '<=', now())
            ->chunkById(200, function ($imports) use (&$discarded): void {
                foreach ($imports as $import) {
                    if ($this->deleteFiles($import)) {
                        $import->delete();
                        $discarded++;
                    }
                }
            });

        return $discarded;
    }

    /**
     * Apaga os arquivos do import. False = nada foi apagado (caminho suspeito ou falha de disco): o import fica como está.
     * Falha de disco não interrompe a limpeza — fica para a próxima rodada (só id e classe no log).
     */
    private function deleteFiles(PatientImport $import): bool
    {
        $paths = array_values(array_filter([$import->file_path, $import->errors_file_path], fn ($path): bool => filled($path)));

        foreach ($paths as $path) {
            if (! $this->ownsPath($import, (string) $path)) {
                $this->unsafe++;
                Log::warning('patient_imports.prune_skipped_unsafe_path', ['import_id' => (string) $import->id]); // sem o caminho

                return false;
            }
        }

        try {
            foreach ($paths as $path) {
                Storage::disk()->delete($path);
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('patient_imports.prune_failed', ['import_id' => (string) $import->id, 'exception' => $e::class]);

            return false;
        }
    }

    /** O caminho está dentro de imports/patients/{entity_id}/ DO PRÓPRIO import e não sobe diretório. */
    private function ownsPath(PatientImport $import, string $path): bool
    {
        $prefix = "imports/patients/{$import->entity_id}/";

        return str_starts_with($path, $prefix)
            && strlen($path) > strlen($prefix)
            && ! in_array('..', explode('/', $path), true)
            && ! str_contains($path, "\0");
    }
}
