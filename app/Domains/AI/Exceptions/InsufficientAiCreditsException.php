<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

class InsufficientAiCreditsException extends \RuntimeException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Saldo de créditos insuficiente. Solicitado: {$requested}. Disponível: {$available}.");
    }
}
