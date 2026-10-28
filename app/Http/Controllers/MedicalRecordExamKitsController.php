<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{MedicalRecord, Patient, Schedule};
use App\Services\Documents\ExamKitService;
use Illuminate\Http\JsonResponse;

/**
 * Kits do pedido de exames do prontuário (Fase 2 / B2): os da clínica + os
 * padrão, filtrados pelo convênio DO ATENDIMENTO (agendamento do prontuário)
 * ou, sem ele, do cadastro do paciente — decidido aqui, nunca pela tela.
 * Itens já resolvidos no catálogo de procedimentos da clínica.
 */
class MedicalRecordExamKitsController extends Controller
{
    public function __invoke(Patient $patient, MedicalRecord $medicalrecord, ExamKitService $kits): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        abort_unless((string) $patient->entity_id === $entityId, 404);
        abort_if($medicalrecord->patient_id !== $patient->id, 404);

        $schedule = $medicalrecord->schedule_id
            ? Schedule::query()->where('entity_id', $entityId)->find($medicalrecord->schedule_id)
            : null;

        $covenant = ($schedule?->covenant_id ? $schedule->covenant : null) ?? $patient->covenant;

        return response()->json([
            'covenant' => $covenant ? ['id' => (string) $covenant->id, 'name' => (string) $covenant->name] : null,
            'kits'     => $kits->forRequest($entityId, $covenant ? (string) $covenant->id : null),
        ]);
    }
}
