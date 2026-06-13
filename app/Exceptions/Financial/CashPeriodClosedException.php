<?php

declare(strict_types=1);

namespace App\Exceptions\Financial;

use RuntimeException;

/**
 * Lançada quando se tenta criar ou alterar um lançamento de caixa cuja data
 * cai dentro de um período já fechado (fechamento de caixa).
 */
class CashPeriodClosedException extends RuntimeException
{
}
