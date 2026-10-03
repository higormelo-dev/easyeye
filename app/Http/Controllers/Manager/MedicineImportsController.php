<?php

namespace App\Http\Controllers\Manager;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\MedicineImportRequest;
use App\Jobs\ProcessMedicineImportJob;
use App\Models\MedicineImport;
use Illuminate\Http\{RedirectResponse, UploadedFile};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carga do catálogo global de medicamentos: download direto da CMED/Anvisa
 * ("Atualizar agora") ou envio dos arquivos — o processamento (~1 min pra
 * lista completa) roda em fila (ProcessMedicineImportJob); o progresso chega
 * à tela por WebSocket (ImportProgressUpdated).
 */
class MedicineImportsController extends Controller
{
    public function store(MedicineImportRequest $request): RedirectResponse
    {
        $data   = $request->validated();
        $upload = $data['source'] === 'upload';

        // Uma carga por vez: duas importações simultâneas disputariam a
        // varredura que desativa as apresentações que saíram da lista.
        $running = MedicineImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            throw ValidationException::withMessages([($upload ? 'cmed_file' : 'source') => __('manager_medicines.import_in_progress')]);
        }

        if (! $upload) {
            $import = MedicineImport::query()->create([
                'user_id' => $request->user()->id,
                'source'  => MedicineImport::SOURCE_CMED,
                'force'   => (bool) ($data['force'] ?? false),
                'status'  => ImportStatus::Pending,
            ]);

            ProcessMedicineImportJob::dispatch($import);

            return back()->with('success', __('manager_medicines.sync_queued'));
        }

        $folder = 'imports/medicines/' . Str::uuid7();
        $cmed   = $request->file('cmed_file');
        $open   = $request->file('open_data_file');

        $import = MedicineImport::query()->create([
            'user_id'                 => $request->user()->id,
            'source'                  => MedicineImport::SOURCE_UPLOAD,
            'status'                  => ImportStatus::Pending,
            'cmed_file_path'          => $cmed->storeAs($folder, 'cmed.' . $this->extension($cmed)),
            'cmed_original_name'      => mb_substr($cmed->getClientOriginalName(), 0, 255),
            'open_data_file_path'     => $open?->storeAs($folder, 'dados_abertos.' . $this->extension($open)),
            'open_data_original_name' => $open ? mb_substr($open->getClientOriginalName(), 0, 255) : null,
        ]);

        ProcessMedicineImportJob::dispatch($import);

        return back()->with('success', __('manager_medicines.import_queued'));
    }

    /**
     * Cancela uma carga PARADA (na fila sem começar — worker fora do ar — ou
     * sem progresso além do timeout do job). Sem isto ela bloqueava novas
     * cargas para sempre. Em andamento normal: recusa.
     */
    public function cancel(string $import): RedirectResponse
    {
        $model = MedicineImport::query()->findOrFail($import);

        if (! $model->isStalled()) {
            throw ValidationException::withMessages(['import' => __('manager_medicines.import_not_stalled')]);
        }

        $model->update([
            'status'      => ImportStatus::Cancelled,
            'phase'       => null,
            'error'       => __('manager_medicines.import_cancelled_reason'),
            'finished_at' => now(),
        ]);

        return back()->with('success', __('manager_medicines.import_cancelled'));
    }

    /** Extensão do nome enviado, só letras (já validada por mimes). */
    private function extension(UploadedFile $file): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower($file->getClientOriginalExtension())) ?: 'bin';
    }
}
