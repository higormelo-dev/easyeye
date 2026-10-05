<?php

namespace App\Enums\Billing;

/**
 * Forma de pagamento escolhida no checkout transparente. Mesmo vocabulário do
 * CreateChargeDTO::$paymentMethod (pix, boleto, credit_card).
 */
enum CheckoutMethod: string
{
    case Pix    = 'pix';
    case Boleto = 'boleto';
    case Card   = 'credit_card';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $method) => $method->value, self::cases());
    }

    public function isCard(): bool
    {
        return $this === self::Card;
    }
}
