<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origem de um item do catálogo de medicamentos.
 *
 * Manual: curado à mão (OphthalmicMedicinesSeeder / manager), com posologia
 * sugerida. Cmed: importado da lista de preços CMED/Anvisa — uma linha por
 * apresentação; dados cadastrais são sobrescritos a cada reimportação.
 */
enum MedicineSource: string
{
    case Manual = 'manual';
    case Cmed   = 'cmed';

    public function label(): string
    {
        return __('manager_medicines.source_' . $this->value);
    }
}
