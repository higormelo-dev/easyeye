<?php

namespace App\Http\Controllers;

use App\Enums\ImportStatus;
use App\Jobs\ProcessScheduleImportJob;
use App\Models\ScheduleImport;
use App\Services\ScheduleImportService;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduleImportsController extends Controller
{
    public function __construct(
        private readonly ScheduleImportService $importService,
    ) {
    }

    public function index(): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');

        $imports = ScheduleImport::where('entity_id', $entityId)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $pendingImport = $imports->first(fn ($i) => \in_array($i->status->value, ['pending', 'processing'], true));

        return Inertia::render('Panel/Schedules/Import', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.schedules'), 'url' => route('panel.schedules.index'), 'active' => false],
                ['label' => __('imports.schedules.title'), 'url' => '#', 'active' => true],
            ],
            'imports'        => $imports->map(fn (ScheduleImport $i) => $this->serializeImport($i)),
            'pending_import' => $pendingImport ? $this->serializeImport($pendingImport) : null,
            'preview_id'     => session('schedule_import_preview_id'),
            'urls'           => [
                'store'     => route('panel.schedules.import.store'),
                'template'  => route('panel.schedules.import.template'),
                'schedules' => route('panel.schedules.index'),
            ],
            't' => trans('imports.schedules'),
        ]);
    }

    private function serializeImport(ScheduleImport $i): array
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
            // Progresso chega por WebSocket (canal privado), sem polling HTTP.
            'channel' => $i->broadcastChannelName(),
            'urls'    => [
                'confirm' => route('panel.schedules.import.confirm', $i->id),
                'cancel'  => route('panel.schedules.import.cancel', $i->id),
                'errors'  => $i->errors_file_path
                    ? route('panel.schedules.import.errors', $i->id)
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
            'file.required' => __('imports.schedules.validation.file_required'),
            'file.mimes'    => __('imports.schedules.validation.file_mimes'),
            'file.max'      => __('imports.schedules.validation.file_max'),
        ]);

        $entityId = session('selected_entity_id');

        $active = ScheduleImport::where('entity_id', $entityId)
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($active) {
            return back()->withErrors(['file' => __('imports.schedules.validation.import_running')]);
        }

        $file = $request->file('file');
        $path = $file->storeAs(
            "imports/schedules/{$entityId}",
            Str::uuid() . '.csv',
        );

        $import = ScheduleImport::create([
            'entity_id'     => $entityId,
            'user_id'       => auth()->id(),
            'status'        => ImportStatus::Pending,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
        ]);

        $preview = $this->importService->generatePreview($import);
        $import->update(['preview' => $preview]);

        return redirect()
            ->route('panel.schedules.import.index')
            ->with('schedule_import_preview_id', $import->id);
    }

    /**
     * Confirma o import após o usuário revisar o preview — dispara o job.
     */
    public function confirm(ScheduleImport $scheduleImport): RedirectResponse
    {
        abort_if((string) $scheduleImport->entity_id !== session('selected_entity_id'), 403);
        abort_if($scheduleImport->status !== ImportStatus::Pending, 409);

        $scheduleImport->update(['confirmed_at' => now()]);

        ProcessScheduleImportJob::dispatch($scheduleImport);

        return redirect()
            ->route('panel.schedules.import.index')
            ->with('import_started', $scheduleImport->id);
    }

    /**
     * Cancela um import.
     *
     * - Ainda não confirmado (preview): nenhum job foi disparado, apaga
     *   arquivo e registro com segurança.
     * - Já confirmado (na fila ou já processando): não dá pra apagar sem
     *   risco de o job continuar criando agendamentos com o registro
     *   sumido — só sinaliza o cancelamento. O próprio job para sozinho no
     *   próximo checkpoint (ver ScheduleImportService::process()/doProcess()).
     */
    public function cancel(ScheduleImport $scheduleImport): RedirectResponse
    {
        abort_if((string) $scheduleImport->entity_id !== session('selected_entity_id'), 403);
        abort_if($scheduleImport->status->isDone(), 409);

        if ($scheduleImport->confirmed_at === null) {
            Storage::disk()->delete($scheduleImport->file_path);
            $scheduleImport->delete();

            return redirect()
                ->route('panel.schedules.import.index')
                ->with('message', __('imports.schedules.cancelled'));
        }

        $scheduleImport->update([
            'status'       => ImportStatus::Cancelled,
            'abort_reason' => __('imports.schedules.cancelled'),
            'finished_at'  => now(),
        ]);

        return redirect()
            ->route('panel.schedules.import.index')
            ->with('message', __('imports.schedules.cancel_requested'));
    }

    /**
     * Faz o download do arquivo de erros de um import.
     */
    public function errors(ScheduleImport $scheduleImport): StreamedResponse|RedirectResponse
    {
        abort_if((string) $scheduleImport->entity_id !== session('selected_entity_id'), 404);
        abort_if(! $scheduleImport->errors_file_path, 404);

        // Registro aponta pro CSV mas o arquivo sumiu do disco (storage
        // limpo/migrado, ambiente restaurado só com o banco): download()
        // lançava UnableToRetrieveMetadata → 500. Volta pra tela com aviso.
        if (! Storage::disk()->exists($scheduleImport->errors_file_path)) {
            return back()->with('error', __('imports.errors_file_missing'));
        }

        return Storage::disk()->download(
            $scheduleImport->errors_file_path,
            "erros_importacao_{$scheduleImport->id}.csv",
        );
    }

    /**
     * Faz o download do modelo de CSV com as colunas esperadas e um exemplo.
     */
    public function template(): StreamedResponse
    {
        $headers = [
            'codigo_importacao_medico', 'crm_medico', 'codigo_importacao_paciente', 'cpf_paciente',
            'nome_paciente', 'data_hora', 'situacao', 'tipo_atendimento', 'especialidade', 'convenio',
            'telefone', 'celular', 'observacoes', 'codigo_importacao',
        ];

        $example = [
            '00042', '123456', '00099', '12345678901',
            'Maria da Silva', '15/06/2026 14:30', 'Atendido', 'Consulta', 'Catarata', 'Unimed',
            '1133334444', '11987654321', 'Paciente veio acompanhado', 'LEGACY-SCH-1',
        ];

        return response()->streamDownload(function () use ($headers, $example) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
            fputcsv($handle, $headers, ';');
            fputcsv($handle, $example, ';');
            fclose($handle);
        }, 'modelo_importacao_agendamentos.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
