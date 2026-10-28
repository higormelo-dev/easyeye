<?php

namespace App\Enums;

/**
 * Base legal para tratamento de dados pessoais.
 * LGPD Art. 7 (dados pessoais) e Art. 11 (dados sensíveis).
 * Toda operação de tratamento de dados DEVE ter uma base legal documentada.
 */
enum LegalBasis: string
{
    case Consent            = 'consent';             // Art. 7, I — Consentimento do titular
    case ContractExecution  = 'contract';            // Art. 7, V — Execução de contrato
    case LegalObligation    = 'legal_obligation';    // Art. 7, II — Cumprimento de obrigação legal
    case HealthProtection   = 'health_protection';   // Art. 7, VIII + Art. 11, II, f — Tutela da saúde
    case LegitimateInterest = 'legitimate_interest'; // Art. 7, IX — Legítimo interesse
    case VitalInterest      = 'vital_interest';      // Art. 7, VII — Proteção da vida

    public function label(): string
    {
        return match ($this) {
            self::Consent            => 'Consentimento (Art. 7, I)',
            self::ContractExecution  => 'Execução de Contrato (Art. 7, V)',
            self::LegalObligation    => 'Obrigação Legal (Art. 7, II)',
            self::HealthProtection   => 'Tutela da Saúde (Art. 7, VIII / Art. 11, II, f)',
            self::LegitimateInterest => 'Legítimo Interesse (Art. 7, IX)',
            self::VitalInterest      => 'Proteção da Vida (Art. 7, VII)',
        };
    }

    /**
     * Base legal padrão para dados clínicos.
     * Tutela da saúde dispensa consentimento em contexto de atendimento médico (Art. 11, II, f).
     */
    public static function defaultForClinicalData(): self
    {
        return self::HealthProtection;
    }
}
