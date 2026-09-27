<?php

namespace App\Http\Controllers;

use App\Enums\{FeatureKey, ImportStatus};
use App\Jobs\ProcessDoctorImportJob;
use App\Models\DoctorImport;
use App\Services\{DoctorImportService, FeatureGateService};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\StreamedResponse;

class DoctorImportsController extends Controller
{
    public function __construct(
        private readonly DoctorImportService $importService,
        private readonly FeatureGateService $featureGate,
    ) {
    }

    public function index(): InertiaResponse
    {
        $entityId   = (string) session('selected_entity_id');
        $planStatus = $this->featureGate->status($entityId, FeatureKey::MaxDoctors);

        $imports = DoctorImport::where('entity_id', $entityId)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $pendingImport = $imports->first(fn ($i) => \in_array($i->status->value, ['pending', 'processing'], true));

        return Inertia::render('Panel/Doctors/Import', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.doctors'), 'url' => route('panel.doctors.index'), 'active' => false],
                ['label' => __('imports.doctors.title'), 'url' => '#', 'active' => true],
            ],
            'imports'        => $imports->map(fn (DoctorImport $i) => $this->serializeImport($i)),
            'pending_import' => $pendingImport ? $this->serializeImport($pendingImport) : null,
            'preview_id'     => session('doctor_import_preview_id'),
            'plan_status'    => [
                'max'       => $planStatus->isUnlimited ? null : $planStatus->limit,
                'used'      => $planStatus->used,
                'available' => $planStatus->isUnlimited ? null : $planStatus->remaining,
            ],
            'urls' => [
                'store'    => route('panel.doctors.import.store'),
                'template' => route('panel.doctors.import.template'),
                'doctors'  => route('panel.doctors.index'),
            ],
            't' => trans('imports.doctors'),
        ]);
    }

    private function serializeImport(DoctorImport $i): array
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
            'urls'            => [
                'status'  => route('panel.doctors.import.status', $i->id),
                'confirm' => route('panel.doctors.import.confirm', $i->id),
                'cancel'  => route('panel.doctors.import.cancel', $i->id),
                'errors'  => $i->errors_file_path
                    ? route('panel.doctors.import.errors', $i->id)
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
            'file.required' => __('imports.doctors.validation.file_required'),
            'file.mimes'    => __('imports.doctors.validation.file_mimes'),
            'file.max'      => __('imports.doctors.validation.file_max'),
        ]);

        $entityId = session('selected_entity_id');

        $active = DoctorImport::where('entity_id', $entityId)
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($active) {
            return back()->withErrors(['file' => __('imports.doctors.validation.import_running')]);
        }

        $file = $request->file('file');
        $path = $file->storeAs(
            "imports/doctors/{$entityId}",
            Str::uuid() . '.csv',
            'private',
        );

        $import = DoctorImport::create([
            'entity_id'     => $entityId,
            'user_id'       => auth()->id(),
            'status'        => ImportStatus::Pending,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
        ]);

        $preview = $this->importService->generatePreview($import);
        $import->update(['preview' => $preview]);

        return redirect()
            ->route('panel.doctors.import.index')
            ->with('doctor_import_preview_id', $import->id);
    }

    /**
     * Confirma o import após o usuário revisar o preview — dispara o job.
     */
    public function confirm(DoctorImport $doctorImport): RedirectResponse
    {
        abort_if((string) $doctorImport->entity_id !== session('selected_entity_id'), 403);
        abort_if($doctorImport->status !== ImportStatus::Pending, 409);

        $doctorImport->update(['confirmed_at' => now()]);

        ProcessDoctorImportJob::dispatch($doctorImport);

        return redirect()
            ->route('panel.doctors.import.index')
            ->with('import_started', $doctorImport->id);
    }

    /**
     * Cancela um import.
     *
     * - Ainda não confirmado (preview): nenhum job foi disparado, apaga
     *   arquivo e registro com segurança.
     * - Já confirmado (na fila ou já processando): não dá pra apagar sem
     *   risco de o job continuar criando médicos/logins com o registro
     *   sumido — só sinaliza o cancelamento. O próprio job para sozinho no
     *   próximo checkpoint (ver DoctorImportService::process()/doProcess()).
     */
    public function cancel(DoctorImport $doctorImport): RedirectResponse
    {
        abort_if((string) $doctorImport->entity_id !== session('selected_entity_id'), 403);
        abort_if($doctorImport->status->isDone(), 409);

        if ($doctorImport->confirmed_at === null) {
            Storage::disk('private')->delete($doctorImport->file_path);
            $doctorImport->delete();

            return redirect()
                ->route('panel.doctors.import.index')
                ->with('message', __('imports.doctors.cancelled'));
        }

        $doctorImport->update([
            'status'       => ImportStatus::Cancelled,
            'abort_reason' => __('imports.doctors.cancelled'),
            'finished_at'  => now(),
        ]);

        return redirect()
            ->route('panel.doctors.import.index')
            ->with('message', __('imports.doctors.cancel_requested'));
    }

    /**
     * Retorna o estado atual de um import como JSON (polling pelo front-end).
     */
    public function status(DoctorImport $doctorImport): JsonResponse
    {
        abort_if((string) $doctorImport->entity_id !== session('selected_entity_id'), 404);

        return response()->json([
            'id'              => $doctorImport->id,
            'status'          => $doctorImport->status->value,
            'status_label'    => $doctorImport->status->label(),
            'status_color'    => $doctorImport->status->color(),
            'total_rows'      => $doctorImport->total_rows,
            'processed_rows'  => $doctorImport->processed_rows,
            'imported_rows'   => $doctorImport->imported_rows,
            'skipped_rows'    => $doctorImport->skipped_rows,
            'error_rows'      => $doctorImport->error_rows,
            'progress'        => $doctorImport->progressPercent(),
            'is_done'         => $doctorImport->status->isDone(),
            'abort_reason'    => $doctorImport->abort_reason,
            'has_errors_file' => $doctorImport->errors_file_path !== null,
            'errors_url'      => $doctorImport->errors_file_path
                ? route('panel.doctors.import.errors', $doctorImport)
                : null,
        ]);
    }

    /**
     * Faz o download do arquivo de erros de um import.
     */
    public function errors(DoctorImport $doctorImport): StreamedResponse
    {
        abort_if((string) $doctorImport->entity_id !== session('selected_entity_id'), 404);
        abort_if(! $doctorImport->errors_file_path, 404);

        return Storage::disk('private')->download(
            $doctorImport->errors_file_path,
            "erros_importacao_{$doctorImport->id}.csv",
        );
    }

    /**
     * Faz o download do modelo de CSV com as colunas esperadas e um exemplo.
     */
    public function template(): StreamedResponse
    {
        $headers = [
            'nome', 'apelido', 'cpf', 'crm', 'crm_especialidade', 'cor', 'email',
            'cbo', 'telefone', 'celular', 'whatsapp', 'observacoes', 'codigo_importacao',
        ];

        $example = [
            'João da Silva', 'Dr. João', '12345678901', '123456', 'Oftalmologia', '#FF0000', 'joao.silva@email.com',
            '225265', '1133334444', '11987654321', 'sim', 'Especialista em retina', '00042',
        ];

        return response()->streamDownload(function () use ($headers, $example) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
            fputcsv($handle, $headers, ';');
            fputcsv($handle, $example, ';');
            fclose($handle);
        }, 'modelo_importacao_medicos.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
