<?php

namespace App\DTOs\Billing;

/**
 * Cartão guardado no gateway: o id/token de lá (card_…, pm_…, CARD_…) e o
 * que a tela mostra (bandeira e 4 últimos dígitos). Nunca número, CVV ou
 * validade.
 */
readonly class SavedCardDTO
{
    public function __construct(
        public string $id,
        public ?string $brand = null,
        public ?string $last4 = null,
    ) {
    }

    public static function make(mixed $id, mixed $brand = null, mixed $last4 = null): ?self
    {
        if (! is_scalar($id) || trim((string) $id) === '') {
            return null;
        }

        $digits = is_scalar($last4) ? substr((string) preg_replace('/\D/', '', (string) $last4), -4) : '';
        $brand  = is_scalar($brand) ? mb_substr(trim((string) $brand), 0, 30) : '';

        return new self(
            id: mb_substr(trim((string) $id), 0, 191),
            brand: $brand !== '' ? strtolower($brand) : null,
            last4: strlen($digits) === 4 ? $digits : null,
        );
    }
}
