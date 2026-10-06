<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origem da posologia sugerida de um item do catálogo global.
 *
 * Manual: digitada ou revisada pelo admin (modal de edição). Ai: gerada em
 * lote pela IA e ainda NÃO revisada — selo "IA – revisar" no manager; no
 * receituário segue como sugestão (o médico sempre revisa).
 */
enum MedicinePosologySource: string
{
    case Manual = 'manual';
    case Ai     = 'ai';
}
