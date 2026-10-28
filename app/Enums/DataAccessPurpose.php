<?php

namespace App\Enums;

/**
 * Finalidade do acesso a dados sensíveis (prontuários, exames).
 * Exigido pelo CFM (Res. 2.227/2018) e LGPD Art. 37 — registro de operações de tratamento.
 */
enum DataAccessPurpose: string
{
    case PatientCare     = 'patient_care';     // Atendimento clínico direto
    case Administrative  = 'administrative';   // Uso administrativo (secretaria, recepção)
    case Report          = 'report';           // Geração de relatório/laudo
    case Export          = 'export';           // Exportação de dados
    case LgpdRequest     = 'lgpd_request';     // Atendimento de solicitação LGPD
    case ApiAccess       = 'api_access';       // Acesso via API de integradores
    case EmergencyAccess = 'emergency_access'; // Acesso emergencial com justificativa

    public function label(): string
    {
        return match ($this) {
            self::PatientCare     => 'Atendimento Clínico',
            self::Administrative  => 'Administrativo',
            self::Report          => 'Relatório / Laudo',
            self::Export          => 'Exportação de Dados',
            self::LgpdRequest     => 'Solicitação LGPD',
            self::ApiAccess       => 'Acesso via API',
            self::EmergencyAccess => 'Acesso Emergencial',
        };
    }

    /** Acesso emergencial exige justificativa textual obrigatória */
    public function requiresJustification(): bool
    {
        return $this === self::EmergencyAccess;
    }
}
