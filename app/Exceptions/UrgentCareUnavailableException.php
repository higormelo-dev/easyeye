<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Mudança recusada no pronto atendimento: o item saiu da fila (outro médico
 * atendeu, a recepção removeu) ou o paciente já está na fila de hoje. A
 * mensagem já vem traduzida; nada é gravado (rollback).
 */
class UrgentCareUnavailableException extends RuntimeException
{
}
