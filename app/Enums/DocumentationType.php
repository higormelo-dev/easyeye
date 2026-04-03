<?php

namespace App\Enums;

enum DocumentationType: string
{
    case Prescription = 'prescription'; // Receituário médico
    case Procedure    = 'procedure';    // Solicitação de exame / procedimento
    case Certificate  = 'certificate';  // Atestado médico
    case Referral     = 'referral';     // Encaminhamento
    case Report       = 'report';       // Laudo / relatório geral
    case Tonometry    = 'tonometry';    // Registro estruturado de tonometria (JSON)

    public function label(): string
    {
        return __('actions.documentation.types.' . $this->value);
    }

    /** Retorna os valores aceitos para usar em regras de validação. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
