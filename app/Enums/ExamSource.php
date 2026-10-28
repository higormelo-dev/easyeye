<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origem do exame no Gerenciador de exames: capturado via integrador
 * (equipamento) ou importado manualmente de fonte externa.
 */
enum ExamSource: string
{
    case Integrator     = 'integrator';
    case ExternalImport = 'external_import';
    /** Foto/vídeo curto capturado ao vivo pela câmera do navegador (Fase 5). */
    case BrowserCapture = 'browser_capture';

    public function label(): string
    {
        return match ($this) {
            self::Integrator     => 'Integrador',
            self::ExternalImport => 'Importação Externa',
            self::BrowserCapture => 'Captura pelo Navegador',
        };
    }
}
