<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\DataAccessPurpose;
use App\Services\DataAccessLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * Adiciona logging de acesso (leitura) a dados sensíveis em controllers.
 *
 * CFM Res. 2.227/2018 — todo acesso ao prontuário deve ser registrado.
 * LGPD Art. 37 — registro das operações de tratamento de dados.
 *
 * Uso no controller:
 *
 *   use LogsDataAccess;
 *
 *   public function show(MedicalRecord $record) {
 *       $this->logAccess($record, DataAccessPurpose::PatientCare);
 *       // ...
 *   }
 *
 *   // Para acesso emergencial (justificativa obrigatória):
 *   $this->logAccess($record, DataAccessPurpose::EmergencyAccess, 'Paciente inconsciente na recepção');
 */
trait LogsDataAccess
{
    /**
     * Registra o acesso (leitura) a um recurso sensível.
     *
     * @param Model             $resource      O model acessado (MedicalRecord, PatientExam, People…)
     * @param DataAccessPurpose $purpose       Finalidade do acesso
     * @param string|null       $justification Obrigatório para EmergencyAccess
     * @param string|null       $patientId     UUID do paciente (inferido automaticamente se o model tiver patient_id)
     */
    protected function logAccess(
        Model $resource,
        DataAccessPurpose $purpose = DataAccessPurpose::PatientCare,
        ?string $justification = null,
        ?string $patientId = null,
    ): void {
        app(DataAccessLogService::class)->log(
            resource: $resource,
            purpose: $purpose,
            justification: $justification,
            patientId: $patientId,
        );
    }
}
