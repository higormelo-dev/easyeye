<?php

declare(strict_types=1);

namespace App\Exceptions\Financial;

use RuntimeException;

/**
 * Lançada quando se tenta registrar um segundo lançamento de caixa ativo
 * para o mesmo agendamento.
 */
class DuplicateCashEntryException extends RuntimeException
{
}
