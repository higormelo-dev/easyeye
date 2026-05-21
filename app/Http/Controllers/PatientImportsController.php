<?php

namespace App\Http\Controllers;

use App\Enums\{FeatureKey, ImportStatus};
use App\Jobs\ProcessPatientImportJob;
use App\Models\{PatientImport};
use App\Services\{FeatureGateService, PatientImportService};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientImportsController extends Controller
{
    public function __construct(
        private readonly PatientImportService $importService,
        private readonly FeatureGateService $featureGate,
    ) {
    }

    public function index(): InertiaResponse
    {
        $entityId   = (string) session('selected_entity_id');
        $planStatus = $this->featureGate->status($entityId, FeatureKey::MaxPatients);

        $imports = PatientImport::where('entity_id', $entityId)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $pendingImport = $imports->first(fn ($i) => \in_array($i->status->value, ['pending', 'processing'], true));

        return Inertia::render('Panel/Patients/Import', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'),  'url' => route('panel.dashboard'),       'active' => false],
                ['label' => __('actions.sidemenu.patients'),   'url' => route('panel.patients.index'),  'active' => false],
                ['label' => __('imports.patients.title'),      'url' => '#',                            'active' => true],
            ],
            'imports' => $imports->map(fn (PatientImport $i) => $this->serializeImport($i)),
            'pending_import' => $pendingImport ? $this->serializeImport($pendingImport) : null,
            'preview_id'     => session('import_preview_id'),
            'plan_status'    => [
                'max'       => $planStatus['max']       ?? null,
                'used'      => $planStatus['used']      ?? 0,
                'available' => $planStatus['available'] ?? null,
            ],
            'urls' => [
                'store'    => route('panel.patients.import.store'),
                'template' => route('panel.patients.import.template'),
                'patients' => route('panel.patients.index'),
            ],
            't' => trans('imports.patients'),
        ]);
    }

    /**
     * Serialização compartilhada (lista e item pendente).
     */
    private function serializeImport(PatientImport $i): array
    {
        return [
            'id'              => (string) $i->id,
            'original_name'   => $i->original_name,
            'status'          => $i->status->value,
            'status_label'    => $i->status->label(),
            'status_color'    => $i->status->color(),
            'is_done'         => $i->status->isDone(),
            'is_pending'      => $i->status->value === 'pending',
            'created_at'      => $i->created_at?->format('d/m/Y H:i'),
            'confirmed_at'    => $i->confirmed_at?->format('d/m/Y H:i'),
            'total_rows'      => (int) $i->total_rows,
            'processed_rows'  => (int) $i->processed_rows,
            'imported_rows'   => (int) $i->imported_rows,
            'skipped_rows'    => (int) $i->skipped_rows,
            'error_rows'      => (int) $i->error_rows,
            'progress'        => $i->progressPercent(),
            'abort_reason'    => $i->abort_reason,
            'user_name'       => $i->user?->name,
            'preview'         => $i->preview,
            'has_errors_file' => $i->errors_file_path !== null,
            'urls' => [
                'status'  => route('panel.patients.import.status',  $i->id),
                'confirm' => route('panel.patients.import.confirm', $i->id),
                'cancel'  => route('panel.patients.import.cancel',  $i->id),
                'errors'  => $i->errors_file_path
                    ? route('panel.patients.import.errors', $i->id)
                    : null,
            ],
        ];
    }

    /**
     * Recebe o CSV, gera preview estruturado e aguarda confirmação do usuário.
     * NÃO dispara o job aqui — apenas salva o arquivo e analisa as colunas.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ], [
            'file.required' => __('imports.patients.validation.file_required'),
            'file.mimes'    => __('imports.patients.validation.file_mimes'),
            'file.max'      => __('imports.patients.validation.file_max'),
        ]);

        $entityId = session('selected_entity_id');

        // Guarda de concorrência: rejeita se já há import ativo nesta entidade
        $active = PatientImport::where('entity_id', $entityId)
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($active) {
            return back()->withErrors(['file' => __('imports.patients.validation.import_running')]);
        }

        $file = $request->file('file');
        $path = $file->storeAs(
            "imports/patients/{$entityId}",
            Str::uuid() . '.csv',
            'private',
        );

        $import = PatientImport::create([
            'entity_id'     => $entityId,
            'user_id'       => auth()->id(),
            'status'        => ImportStatus::Pending,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
        ]);

        // Gera preview (leitura rápida: cabeçalho + 5 linhas)
        $preview = $this->importService->generatePreview($import);
        $import->update(['preview' => $preview]);

        return redirect()
            ->route('panel.patients.import.index')
            ->with('import_preview_id', $import->id);
    }

    /**
     * Confirma o import após o usuário revisar o preview — dispara o job.
     */
    public function confirm(PatientImport $patientImport): RedirectResponse
    {
        abort_if((string) $patientImport->entity_id !== session('selected_entity_id'), 403);
        abort_if($patientImport->status !== ImportStatus::Pending, 409);

        $patientImport->update(['confirmed_at' => now()]);

        ProcessPatientImportJob::dispatch($patientImport);

        return redirect()
            ->route('panel.patients.import.index')
            ->with('import_started', $patientImport->id);
    }

    /**
     * Cancela um import pendente (ainda não confirmado).
     */
    public function cancel(PatientImport $patientImport): RedirectResponse
    {
        abort_if((string) $patientImport->entity_id !== session('selected_entity_id'), 403);
        abort_if($patientImport->status !== ImportStatus::Pending, 409);

        Storage::disk('private')->delete($patientImport->file_path);
        $patientImport->delete();

        return redirect()
            ->route('panel.patients.import.index')
            ->with('message', __('imports.patients.cancelled'));
    }

    /**
     * Retorna o estado atual de um import como JSON (polling pelo Alpine.js).
     */
    public function status(PatientImport $patientImport): JsonResponse
    {
        abort_if((string) $patientImport->entity_id !== session('selected_entity_id'), 404);

        return response()->json([
            'id'              => $patientImport->id,
            'status'          => $patientImport->status->value,
            'status_label'    => $patientImport->status->label(),
            'status_color'    => $patientImport->status->color(),
            'total_rows'      => $patientImport->total_rows,
            'processed_rows'  => $patientImport->processed_rows,
            'imported_rows'   => $patientImport->imported_rows,
            'skipped_rows'    => $patientImport->skipped_rows,
            'error_rows'      => $patientImport->error_rows,
            'progress'        => $patientImport->progressPercent(),
            'is_done'         => $patientImport->status->isDone(),
            'abort_reason'    => $patientImport->abort_reason,
            'has_errors_file' => $patientImport->errors_file_path !== null,
            'errors_url'      => $patientImport->errors_file_path
                ? route('panel.patients.import.errors', $patientImport)
                : null,
        ]);
    }

    /**
     * Faz o download do arquivo de erros de um import.
     */
    public function errors(PatientImport $patientImport): StreamedResponse
    {
        abort_if((string) $patientImport->entity_id !== session('selected_entity_id'), 404);
        abort_if(! $patientImport->errors_file_path, 404);

        return Storage::disk('private')->download(
            $patientImport->errors_file_path,
            "erros_importacao_{$patientImport->id}.csv",
        );
    }

    /**
     * Faz o download do modelo de CSV com as colunas esperadas e um exemplo.
     */
    public function template(): StreamedResponse
    {
        $headers = [
            'nome', 'celular', 'telefone', 'email', 'cpf',
            'data_nascimento', 'sexo', 'estado_civil',
            'nome_mae', 'nome_pai',
            'cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'estado', 'pais',
            'convenio', 'carteirinha',
        ];

        $example = [
            'João da Silva', '11987654321', '1133334444', 'joao@email.com', '12345678901',
            '15/06/1980', 'M', '1',
            'Maria da Silva', 'José da Silva',
            '01310100', 'Av. Paulista', '1000', 'Apto 1', 'Bela Vista', 'São Paulo', 'SP', 'Brasil',
            'Unimed', '123456789',
        ];

        return response()->streamDownload(function () use ($headers, $example) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
            fputcsv($handle, $headers, ';');
            fputcsv($handle, $example, ';');
            fclose($handle);
        }, 'modelo_importacao_pacientes.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
