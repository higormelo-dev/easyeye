<?php

namespace App\Enums;

/**
 * Tipos de solicitação de direitos do titular.
 * LGPD Art. 18 — Direitos do titular de dados.
 */
enum LgpdRequestType: string
{
    case Access        = 'access';            // Art. 18, II  — Confirmação e acesso aos dados
    case Correction    = 'correction';         // Art. 18, III — Correção de dados incompletos/inexatos
    case Anonymization = 'anonymization';      // Art. 18, IV  — Anonimização ou bloqueio
    case Portability   = 'portability';        // Art. 18, V   — Portabilidade dos dados
    case Deletion      = 'deletion';           // Art. 18, VI  — Eliminação dos dados
    case RevokeConsent = 'revoke_consent';     // Art. 18, IX  — Revogação do consentimento
    case Information   = 'information';        // Art. 18, VII — Informação sobre compartilhamento
    case Opposition    = 'opposition';         // Art. 18, X   — Oposição ao tratamento

    public function label(): string
    {
        return match ($this) {
            self::Access        => 'Acesso aos Dados (Art. 18, II)',
            self::Correction    => 'Correção de Dados (Art. 18, III)',
            self::Anonymization => 'Anonimização / Bloqueio (Art. 18, IV)',
            self::Portability   => 'Portabilidade (Art. 18, V)',
            self::Deletion      => 'Eliminação de Dados (Art. 18, VI)',
            self::RevokeConsent => 'Revogação de Consentimento (Art. 18, IX)',
            self::Information   => 'Informação sobre Compartilhamento (Art. 18, VII)',
            self::Opposition    => 'Oposição ao Tratamento (Art. 18, X)',
        };
    }

    /**
     * Prazo máximo de resposta em dias.
     * LGPD Art. 23 — 15 dias para confirmar a existência de tratamento.
     */
    public function deadlineDays(): int
    {
        return 15;
    }
}
