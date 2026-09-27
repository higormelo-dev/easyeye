<?php

declare(strict_types=1);

namespace App\Exceptions\Financial;

use RuntimeException;

/**
 * Lançada quando o número do recurso de glosa continua colidindo no índice
 * único (entity_id, appeal_number) depois de todas as tentativas — situação
 * transitória (escritor concorrente fora do lock). O HTTP responde 409 e o
 * usuário pode tentar de novo; nada é gravado.
 */
class AppealNumberUnavailableException extends RuntimeException
{
}
