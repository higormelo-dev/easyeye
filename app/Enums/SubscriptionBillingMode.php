<?php

namespace App\Enums;

/**
 * Como a assinatura é paga. Trial não tem modo (ainda não é receita).
 *
 * - Gateway: cobrança automática por um gateway de pagamento — a única
 *   forma de receita.
 * - Complimentary: cortesia, sem cobrança, liberada pelo manager com data
 *   de término. Não é receita nem gera comissão de parceiro.
 */
enum SubscriptionBillingMode: string
{
    case Gateway       = 'gateway';
    case Complimentary = 'complimentary';

    public function label(): string
    {
        return __("subscriptions.billing_mode.{$this->value}");
    }

    public function isBillable(): bool
    {
        return $this === self::Gateway;
    }

    /** @return list<string> */
    public static function billableValues(): array
    {
        return [self::Gateway->value];
    }
}
