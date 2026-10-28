<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origem de um convênio do catálogo global.
 *
 * Manual: cadastrado à mão no manager (ou convênio sem registro ANS, como o
 * PARTICULAR). Ans: operadora do Cadastro de Operadoras da ANS — os dados
 * oficiais (razão social, CNPJ, modalidade, UF, situação) vêm da importação.
 */
enum CovenantSource: string
{
    case Manual = 'manual';
    case Ans    = 'ans';

    public function label(): string
    {
        return __('manager_covenants.source_' . $this->value);
    }
}
