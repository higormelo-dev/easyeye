<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origem do exame no Gerenciador de Imagens: capturado via integrador
 * (equipamento) ou importado manualmente de fonte externa.
 */
enum ExamSource: string
{
    case Integrator     = 'integrator';
    case ExternalImport = 'external_import';

    public function label(): string
    {
        return match ($this) {
            self::Integrator     => 'Integrador',
            self::ExternalImport => 'Importação Externa',
        };
    }
}
