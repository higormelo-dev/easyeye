<?php

namespace App\Enums\Billing;

enum PaymentStatus: string
{
    case Pending    = 'pending';
    case Authorized = 'authorized';
    case Paid       = 'paid';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';
    case Refunded   = 'refunded';
    case Chargeback = 'chargeback';

    /**
     * Pagamento confirmado para uma fatura que JÁ estava paga por outra
     * cobrança (ex.: dois checkouts de cartão pagos): o dinheiro entrou, mas
     * não quita nada — fica registrado para o manager estornar pela tela.
     * Nunca conta como "a cobrança que quitou" (Invoice::isSettledByCharge).
     */
    case Duplicate = 'duplicate';

    /** Status da cobrança devolvido pelo gateway (já normalizado pelo adapter). */
    public static function fromGatewayStatus(?string $status): self
    {
        return match ($status) {
            'paid', 'succeeded', 'approved' => self::Paid,
            'authorized' => self::Authorized,
            'cancelled', 'canceled' => self::Cancelled,
            'refunded'   => self::Refunded,
            'chargeback' => self::Chargeback,
            'failed', 'error', 'declined', 'refused' => self::Failed,
            default => self::Pending,
        };
    }

    /** A cobrança não vale mais: recusada ou cancelada no gateway. */
    public function isUnusable(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled], true);
    }
}
