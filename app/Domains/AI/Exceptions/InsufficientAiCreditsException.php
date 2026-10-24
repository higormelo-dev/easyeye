<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use App\Support\Money;
use RuntimeException;

/** Saldo de IA insuficiente para a reserva pedida. Valores em CENTAVOS de BRL. */
class InsufficientAiCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requestedCents,
        public readonly int $availableCents,
    ) {
        parent::__construct('Saldo de IA insuficiente. Solicitado: ' . Money::formatBrl($requestedCents) . '. Disponível: ' . Money::formatBrl($availableCents) . '.');
    }

    public function missingCents(): int
    {
        return max(0, $this->requestedCents - $this->availableCents);
    }
}
