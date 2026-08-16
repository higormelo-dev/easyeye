<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origem do diagnóstico vinculado a um exame no Gerenciador de Imagens:
 * catálogo CID-10 global ou diagnóstico customizado da clínica.
 */
enum DiagnosisType: string
{
    case Cid10  = 'cid10';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Cid10  => 'CID-10',
            self::Custom => 'Customizado',
        };
    }
}
