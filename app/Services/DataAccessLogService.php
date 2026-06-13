<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DataAccessPurpose;
use App\Models\DataAccessLog;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Log;

class DataAccessLogService
{
    /**
     * Registra o acesso (leitura) a um recurso sensível.
     * CFM Res. 2.227/2018 + LGPD Art. 37.
     *
     * @param Model             $resource      Model acessado (MedicalRecord, PatientExam…)
     * @param DataAccessPurpose $purpose       Finalidade do acesso
     * @param string|null       $justification Obrigatório para EmergencyAccess
     * @param string|null       $patientId     UUID do paciente; inferido automaticamente se possível
     */
    public function log(
        Model $resource,
        DataAccessPurpose $purpose,
        ?string $justification = null,
        ?string $patientId = null,
    ): void {
        if ($purpose->requiresJustification() && blank($justification)) {
            Log::warning('DataAccessLogService: acesso emergencial sem justificativa', [
                'resource' => get_class($resource),
                'id'       => $resource->getKey(),
                'user_id'  => AuditContext::userId(),
            ]);
        }

        DataAccessLog::create([
            'entity_id'     => session('selected_entity_id') ?? $resource->getAttribute('entity_id'),
            'user_id'       => AuditContext::userId(),
            'resource_type' => get_class($resource),
            'resource_id'   => $resource->getKey(),
            'patient_id'    => $patientId ?? $this->inferPatientId($resource),
            'purpose'       => $purpose,
            'justification' => $justification,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'accessed_at'   => now(),
        ]);
    }

    /**
     * Tenta inferir o patient_id do model.
     * Suporta: patient_id direto, ou via relação patient() HasOne/BelongsTo.
     */
    private function inferPatientId(Model $resource): ?string
    {
        if ($resource->getAttribute('patient_id')) {
            return $resource->getAttribute('patient_id');
        }

        return null;
    }
}
