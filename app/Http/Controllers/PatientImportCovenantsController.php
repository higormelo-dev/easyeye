<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{ImportStatus, Permission};
use App\Http\Requests\PatientImportCovenantMappingsRequest;
use App\Models\{Entity, PatientImport};
use App\Services\Covenants\{PatientImportCovenantMappingService, PatientImportCovenantReportService, PatientImportReprocessService};
use Illuminate\Http\{JsonResponse, Request};

/**
 * Convênios de uma importação de pacientes: relatório (nomes do arquivo × catálogo), de-para em lote e reprocessamento
 * das linhas que falharam por convênio. Só nomes de convênio, contagens e alvos do catálogo — nenhum dado de paciente.
 * Escopo sempre pela clínica da sessão (outra clínica = 404). Criar convênio exige `settings.manage` (no serviço).
 */
class PatientImportCovenantsController extends Controller
{
    public function __construct(
        private readonly PatientImportCovenantReportService $report,
        private readonly PatientImportCovenantMappingService $mappings,
        private readonly PatientImportReprocessService $reprocess,
    ) {
    }

    public function show(PatientImport $patientImport): JsonResponse
    {
        $this->authorizeImport($patientImport);

        return response()->json(['data' => $this->payload($patientImport)]);
    }

    public function update(PatientImportCovenantMappingsRequest $request, PatientImport $patientImport): JsonResponse
    {
        $this->authorizeImport($patientImport);

        $meta = $this->mappings->save($patientImport, $request->validated('mappings'), $request->user());

        return response()->json(['data' => $this->payload($patientImport->refresh()), 'meta' => $meta]);
    }

    public function reprocess(Request $request, PatientImport $patientImport): JsonResponse
    {
        $this->authorizeImport($patientImport);

        $child = $this->reprocess->reprocess($patientImport, $request->user());

        return response()->json(['data' => [
            'import_id'  => (string) $child->id,
            'status'     => $child->status->value,
            'total_rows' => (int) $child->total_rows,
            'channel'    => $child->broadcastChannelName(),
        ]], 202);
    }

    private function authorizeImport(PatientImport $import): void
    {
        abort_if((string) $import->entity_id !== (string) session('selected_entity_id'), 404);
    }

    /** @return array<string, mixed> */
    private function payload(PatientImport $import): array
    {
        $report = $this->report->forImport($import);
        $entity = Entity::query()->findOrFail((string) $import->entity_id);

        return [
            'import' => [
                'id'               => (string) $import->id,
                'original_name'    => $import->original_name,
                'status'           => $import->status->value,
                'is_reprocess'     => $import->isReprocess(),
                'reprocess_of_id'  => $import->reprocess_of_id ? (string) $import->reprocess_of_id : null,
                'files_expire_at'  => $import->files_expire_at?->toIso8601String(),
                'files_expired'    => $this->report->filesExpired($import),
                'report_available' => $report['available'],
            ],
            'permissions' => [
                'can_create_covenant' => (bool) auth()->user()?->hasPermissionInEntity($entity, Permission::SettingsManage),
                'can_reprocess'       => in_array($import->status, [ImportStatus::Done, ImportStatus::Failed], true)
                    && $this->report->originalAvailable($import)
                    && $report['retry_line_count'] > 0,
            ],
            'totals' => $report['totals'],
            // true = o arquivo tem mais nomes distintos que o teto de coleta; a lista vem incompleta (aviso na tela).
            'names_capped' => $report['names_capped'],
            'items'        => $report['items'],
        ];
    }
}
