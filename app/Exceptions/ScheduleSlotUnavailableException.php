<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Horário recusado DENTRO da transação de gravação — o teto de encaixes do
 * turno conferido sob lock (ScheduleService::assertFitInCapacity) ou uma
 * sessão da série que deixou de caber. A mensagem já vem traduzida; quem
 * grava responde 422 e nada fica gravado (rollback).
 */
class ScheduleSlotUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'date_time')
    {
        parent::__construct($message);
    }
}
