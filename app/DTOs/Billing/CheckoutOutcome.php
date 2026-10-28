<?php

namespace App\DTOs\Billing;

/** Resultado do cartão na contratação, preenchido pelo orquestrador. */
final class CheckoutOutcome
{
    public ?array $nextAction = null;

    public ?SavedCardDTO $savedCard = null;

    public ?string $declineMessage = null;

    /** O gateway não tem cartão transparente: a cobrança saiu pelo link. */
    public bool $usedLink = false;
}
