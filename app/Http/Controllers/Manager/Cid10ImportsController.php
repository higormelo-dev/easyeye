<?php

namespace App\Http\Controllers\Manager;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\Cid10ImportRequest;
use App\Jobs\ProcessCid10ImportJob;
use App\Models\Cid10Import;
use App\Services\Cid10\Cid10ImportFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Importação da CID-10 (DATASUS) pela tela Manager → CID-10: o
 * CID10CSV.zip ou os CSVs. Valida e normaliza no envio (Cid10ImportFiles —
 * erro de arquivo aparece na hora), processa em fila (ProcessCid10ImportJob)
 * e o progresso chega à tela por WebSocket (ImportProgressUpdated). Só
 * acrescenta códigos e atualiza o texto oficial; nunca exclui.
 */
class Cid10ImportsController extends Controller
{
    public function __construct(
        private readonly Cid10ImportFiles $files,
    ) {
    }

    public function store(Cid10ImportRequest $request): RedirectResponse
    {
        // Uma por vez: duas cargas simultâneas disputariam as mesmas linhas.
        $running = Cid10Import::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            throw ValidationException::withMessages(['files' => __('manager_cid10.import_in_progress')]);
        }

        $folder = 'imports/cid10/' . Str::uuid7();

        try {
            $stored = $this->files->store(array_values($request->file('files', [])), $folder);
        } catch (Throwable $e) {
            Storage::disk()->deleteDirectory($folder);

            throw $e;
        }

        $import = Cid10Import::query()->create([
            'user_id'       => $request->user()->id,
            'status'        => ImportStatus::Pending,
            'folder'        => $stored['folder'],
            'original_name' => $stored['original_name'],
            'files'         => $stored['files'],
        ]);

        ProcessCid10ImportJob::dispatch($import);

        return back()->with('success', __('manager_cid10.import_queued'));
    }

    /**
     * Cancela uma importação PARADA (na fila sem começar — worker fora do ar
     * — ou sem progresso além do timeout do job). Sem isto ela bloqueava
     * novas importações para sempre. Em andamento normal: recusa.
     */
    public function cancel(string $import): RedirectResponse
    {
        $model = Cid10Import::query()->findOrFail($import);

        if (! $model->isStalled()) {
            throw ValidationException::withMessages(['import' => __('manager_cid10.import_not_stalled')]);
        }

        $model->update([
            'status'      => ImportStatus::Cancelled,
            'phase'       => null,
            'error'       => __('manager_cid10.import_cancelled_reason'),
            'finished_at' => now(),
        ]);

        return back()->with('success', __('manager_cid10.import_cancelled'));
    }
}
