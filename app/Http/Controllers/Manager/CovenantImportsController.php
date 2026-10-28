<?php

namespace App\Http\Controllers\Manager;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\CovenantImportRequest;
use App\Jobs\ProcessCovenantImportJob;
use App\Models\CovenantImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sincronização do catálogo global de convênios com a ANS — baixando direto
 * dos dados abertos (padrão) ou com os CSVs enviados. O processamento roda
 * em fila (ProcessCovenantImportJob); o progresso chega à tela por
 * WebSocket (ImportProgressUpdated).
 */
class CovenantImportsController extends Controller
{
    public function store(CovenantImportRequest $request): RedirectResponse
    {
        // Uma por vez: duas cargas simultâneas disputariam as desativações.
        $running = CovenantImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            throw ValidationException::withMessages(['source' => __('manager_covenants.import_in_progress')]);
        }

        $data      = $request->validated();
        $upload    = $data['source'] === 'upload';
        $folder    = 'imports/covenants/' . Str::uuid7();
        $active    = $upload ? $request->file('active_file') : null;
        $cancelled = $upload ? $request->file('cancelled_file') : null;

        $import = CovenantImport::query()->create([
            'user_id'                 => $request->user()->id,
            'source'                  => $upload ? CovenantImport::SOURCE_UPLOAD : CovenantImport::SOURCE_ANS,
            'status'                  => ImportStatus::Pending,
            'modalities'              => array_values(array_unique($data['modalities'])),
            'active_file_path'        => $active?->storeAs($folder, 'operadoras_ativas.csv'),
            'active_original_name'    => $active ? mb_substr($active->getClientOriginalName(), 0, 255) : null,
            'cancelled_file_path'     => $cancelled?->storeAs($folder, 'operadoras_canceladas.csv'),
            'cancelled_original_name' => $cancelled ? mb_substr($cancelled->getClientOriginalName(), 0, 255) : null,
        ]);

        ProcessCovenantImportJob::dispatch($import);

        return back()->with('success', __('manager_covenants.import_queued'));
    }

    /**
     * Cancela uma sincronização PARADA (na fila sem começar — worker fora do
     * ar — ou sem progresso além do timeout do job). Sem isto ela bloqueava
     * novas sincronizações para sempre. Em andamento normal: recusa.
     */
    public function cancel(string $import): RedirectResponse
    {
        $model = CovenantImport::query()->findOrFail($import);

        if (! $model->isStalled()) {
            throw ValidationException::withMessages(['import' => __('manager_covenants.import_not_stalled')]);
        }

        $model->update([
            'status'      => ImportStatus::Cancelled,
            'phase'       => null,
            'error'       => __('manager_covenants.import_cancelled_reason'),
            'finished_at' => now(),
        ]);

        return back()->with('success', __('manager_covenants.import_cancelled'));
    }
}
