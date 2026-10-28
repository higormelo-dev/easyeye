<?php

declare(strict_types=1);

namespace App\Exceptions\Financial;

use RuntimeException;

/**
 * Lançada quando a glosa, relida sob lock, não está mais em estado recorrível
 * (ex.: outra aba/usuário acabou de abrir o recurso). O HTTP responde 409.
 */
class GlosaNotAppealableException extends RuntimeException
{
}
