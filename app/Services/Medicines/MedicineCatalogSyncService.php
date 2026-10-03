<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use App\Enums\ImportStatus;
use App\Models\MedicineImport;
use Illuminate\Http\File;
use Illuminate\Support\Facades\{Log, Storage};
use Throwable;

/**
 * Carga do catálogo global de medicamentos:
 *
 * - Envio manual: processa os arquivos enviados (como sempre).
 * - Download (botão "Atualizar agora" ou tarefa semanal): baixa a lista CMED
 *   mais recente e a situação dos registros da Anvisa e processa com o mesmo
 *   importador. Se nada mudou desde a última carga (mesma lista e mesmos
 *   dados abertos) termina sem reprocessar — a não ser que o admin force.
 *
 * Arquivos baixados não ficam guardados: são públicos e versionados (a
 * trilha é a versão + o link gravados no import).
 */
class MedicineCatalogSyncService
{
    public const PHASE_DOWNLOADING = 'downloading';

    public function __construct(
        private readonly CmedListDownloader $downloader,
        private readonly AnvisaMedicineImportService $importer,
    ) {
    }

    public function run(MedicineImport $import): void
    {
        if ($import->source === MedicineImport::SOURCE_UPLOAD) {
            $this->importer->process($import);

            return;
        }

        $import->update([
            'status'     => ImportStatus::Processing,
            'phase'      => self::PHASE_DOWNLOADING,
            'started_at' => now(),
            'error'      => null,
        ]);

        $folder = 'imports/medicines/' . $import->id;
        $temp   = [];

        try {
            $notices     = [];
            $ref         = $this->downloader->latestList();
            $openVersion = $this->downloader->openDataVersion();

            if (! $import->force && $this->unchanged($ref, $openVersion)) {
                $import->update([
                    'status'            => ImportStatus::Done,
                    'phase'             => null,
                    'list_version'      => $ref->version,
                    'list_published_at' => $ref->publishedAt?->toDateString(),
                    'list_url'          => $ref->url,
                    'open_data_version' => $openVersion,
                    'notice'            => __('manager_medicines.sync_unchanged', ['date' => $ref->publishedAt?->isoFormat('L') ?? $ref->version]),
                    'finished_at'       => now(),
                ]);

                return;
            }

            [$ref, $temp[]] = $this->downloadList($ref, $notices);

            $openPath = null;

            try {
                $temp[] = $openPath = $this->downloader->download((string) config('medicines.cmed.open_data_url'), 'csv');
            } catch (CmedDownloadException) {
                // Sem a situação dos registros a carga segue (benefício da dúvida, como no envio sem o arquivo).
                $openVersion = null;
                $notices[]   = __('manager_medicines.sync_open_data_unavailable');
            }

            $import->update([
                'cmed_file_path'          => Storage::disk()->putFileAs($folder, new File($temp[0]), 'cmed.' . $ref->extension),
                'cmed_original_name'      => $ref->filename,
                'open_data_file_path'     => $openPath ? Storage::disk()->putFileAs($folder, new File($openPath), 'dados_abertos.csv') : null,
                'open_data_original_name' => $openPath ? basename((string) parse_url((string) config('medicines.cmed.open_data_url'), PHP_URL_PATH)) : null,
                'list_version'            => $ref->version,
                'list_published_at'       => $ref->publishedAt?->toDateString(),
                'list_url'                => $ref->url,
                'open_data_version'       => $openVersion,
                'notice'                  => $notices === [] ? null : implode(' ', $notices),
            ]);
        } catch (Throwable $e) {
            Log::error('Falha ao baixar as fontes oficiais de medicamentos', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
                'at'        => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            $import->update([
                'status'      => ImportStatus::Failed,
                'phase'       => null,
                'error'       => $e instanceof CmedDownloadException ? $e->getMessage() : __('manager_medicines.import_failed_generic'),
                'finished_at' => now(),
            ]);
            $this->cleanup($folder, $temp, $import);

            return;
        }

        foreach ($temp as $path) {
            @unlink($path);
        }

        try {
            $this->importer->process($import);
        } finally {
            $this->cleanup($folder, [], $import);
        }
    }

    /**
     * Baixa a lista; se o arquivo da página oficial falhar, tenta a reserva
     * do portal de dados abertos (avisando a data, que pode ser antiga).
     *
     * @param list<string> $notices
     *
     * @return array{0: CmedListRef, 1: string}
     */
    private function downloadList(CmedListRef $ref, array &$notices): array
    {
        try {
            $path = $this->downloader->download($ref->url, $ref->extension);
        } catch (CmedDownloadException $e) {
            if ($ref->fallback) {
                throw $e;
            }

            $ref  = $this->downloader->fallbackList();
            $path = $this->downloader->download($ref->url, $ref->extension);
        }

        if ($ref->fallback) {
            $ref = new CmedListRef(
                url: $ref->url,
                version: $ref->version,
                publishedAt: $this->downloader->publishedAtFromCsv($path) ?? $ref->publishedAt,
                filename: $ref->filename,
                extension: $ref->extension,
                fallback: true,
            );
            $notices[] = __('manager_medicines.sync_fallback_used', ['date' => $ref->publishedAt?->isoFormat('L') ?? '—']);
        }

        return [$ref, $path];
    }

    /** Mesma lista e mesmos dados abertos da última carga concluída = nada a fazer. */
    private function unchanged(CmedListRef $ref, ?string $openVersion): bool
    {
        if ($openVersion === null) {
            return false;
        }

        $last = MedicineImport::query()
            ->where('status', ImportStatus::Done->value)
            ->whereNotNull('list_version')
            ->latest('finished_at')
            ->first(['list_version', 'open_data_version']);

        return $last !== null && $last->list_version === $ref->version && $last->open_data_version === $openVersion;
    }

    /** @param list<string> $temp */
    private function cleanup(string $folder, array $temp, MedicineImport $import): void
    {
        foreach ($temp as $path) {
            @unlink($path);
        }

        Storage::disk()->deleteDirectory($folder);

        if ($import->cmed_file_path !== null || $import->open_data_file_path !== null) {
            $import->update(['cmed_file_path' => null, 'open_data_file_path' => null]);
        }
    }
}
