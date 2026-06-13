<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Formas de pagamento do caixa, espelhando as opções do CashierBook
 * do projeto smart_oftal (À Vista, Crédito, Crédito e Dinheiro,
 * Débito e Dinheiro, Transferência Bancária).
 */
enum PaymentMethod: string
{
    case Cash       = 'cash';        // À Vista (dinheiro)
    case Credit     = 'credit';      // Crédito
    case CreditCash = 'credit_cash'; // Crédito e Dinheiro
    case DebitCash  = 'debit_cash';  // Débito e Dinheiro
    case Transfer   = 'transfer';    // Transferência Bancária
    case Courtesy   = 'courtesy';    // Cortesia (lançamento de R$ 0)

    public function label(): string
    {
        return match ($this) {
            self::Cash       => 'À Vista',
            self::Credit     => 'Crédito',
            self::CreditCash => 'Crédito e Dinheiro',
            self::DebitCash  => 'Débito e Dinheiro',
            self::Transfer   => 'Transferência Bancária',
            self::Courtesy   => 'Cortesia',
        };
    }

    /** Cortesia: atendimento sem cobrança — registra entrada de R$ 0. */
    public function isCourtesy(): bool
    {
        return $this === self::Courtesy;
    }

    /** Exibe o campo de parcelas (crédito puro ou crédito + dinheiro). */
    public function usesInstallments(): bool
    {
        return in_array($this, [self::Credit, self::CreditCash], true);
    }

    /** Exibe o campo "Valor do crédito" (pagamento combinado crédito + dinheiro). */
    public function showsCredit(): bool
    {
        return $this === self::CreditCash;
    }

    /** Exibe o campo "Valor do débito" (pagamento combinado débito + dinheiro). */
    public function showsDebit(): bool
    {
        return $this === self::DebitCash;
    }

    /** Exibe o campo "Valor em dinheiro" (qualquer combinação parcial). */
    public function showsCash(): bool
    {
        return in_array($this, [self::CreditCash, self::DebitCash], true);
    }

    /** Pagamento combinado: soma do breakdown deve igualar o valor total. */
    public function isCombined(): bool
    {
        return $this->showsCash();
    }
}
