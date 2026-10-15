<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/**
 * Exame canônico de um protocolo de retorno. O protocolo fala "campimetria"; cada
 * clínica diz quais dos seus tipos de exame (`exam_types`) contam como campimetria
 * (`recall_exam_mappings`). Lista fechada de propósito: o avaliador precisa saber
 * ler cada exame, então um exame novo exige código (e teste) além de conteúdo.
 */
enum RecallExamKey: string
{
    case VisualField       = 'visual_field';
    case OctOpticNerve     = 'oct_optic_nerve';
    case OctMacula         = 'oct_macula';
    case Retinography      = 'retinography';
    case CornealTopography = 'corneal_topography';

    public function label(): string
    {
        return __('recall_protocol.exam_keys.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $key): string => $key->value, self::cases());
    }
}
