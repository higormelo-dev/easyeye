<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bandeira do cartão informada no recebimento particular (lançamento de caixa).
 * Só se aplica às formas de pagamento com cartão (PaymentMethod::usesCard()).
 */
enum CardBrand: string
{
    case Visa       = 'visa';
    case Mastercard = 'mastercard';
    case Elo        = 'elo';
    case Amex       = 'amex';
    case Hipercard  = 'hipercard';
    case Diners     = 'diners';
    case Others     = 'others';

    public function label(): string
    {
        // Texto em lang/{locale}/financial_cash_flow.php.
        return __("financial_cash_flow.card_brands.{$this->value}");
    }
}
