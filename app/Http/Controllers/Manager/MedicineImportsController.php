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
 * Upload da lista CMED/Anvisa pro catálogo global de medicamentos — o
 * processamento (~1 min pra lista completa) roda em fila
 * (ProcessMedicineImportJob); o progresso chega à tela por WebSocket
 * (ImportProgressUpdated).
 */
class MedicineImportsController extends Controller
{
    public function store(MedicineImportRequest $request): RedirectResponse
    {
        // Uma carga por vez: duas importações simultâneas disputariam a
        // varredura que desativa as apresentações que saíram da lista.
        $running = MedicineImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            throw ValidationException::withMessages(['cmed_file' => __('manager_medicines.import_in_progress')]);
        }

        $folder = 'imports/medicines/' . Str::uuid7();
        $cmed   = $request->file('cmed_file');
        $open   = $request->file('open_data_file');

        $import = MedicineImport::query()->create([
            'user_id'                 => $request->user()->id,
            'status'                  => ImportStatus::Pending,
            'cmed_file_path'          => $cmed->storeAs($folder, 'cmed.' . $this->extension($cmed)),
            'cmed_original_name'      => mb_substr($cmed->getClientOriginalName(), 0, 255),
            'open_data_file_path'     => $open?->storeAs($folder, 'dados_abertos.' . $this->extension($open)),
            'open_data_original_name' => $open ? mb_substr($open->getClientOriginalName(), 0, 255) : null,
        ]);

        ProcessMedicineImportJob::dispatch($import);

        return back()->with('success', __('manager_medicines.import_queued'));
    }

    /** Extensão do nome enviado, só letras (já validada por mimes). */
    private function extension(UploadedFile $file): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower($file->getClientOriginalExtension())) ?: 'bin';
    }
}
