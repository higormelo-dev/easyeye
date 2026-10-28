<?php

namespace App\DTOs\Billing;

/**
 * Resultado de guardar (trocar) o cartão da renovação sem cobrar.
 * $nextAction: o banco pediu autenticação (3DS, Stripe) — o front conclui
 * com o SDK e chama de novo com a referência devolvida.
 */
readonly class SaveCardResultDTO
{
    public function __construct(
        public bool $success,
        public ?SavedCardDTO $card = null,
        public ?array $nextAction = null,
        public ?string $errorMessage = null,
    ) {
    }
}
