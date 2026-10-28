<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Ciclo de vida de um fechamento de repasse (App\Models\DoctorPayout).
 *
 * "Pendente" não é um status gravado: é a produção ainda fora de qualquer
 * fechamento válido, calculada ao vivo (DoctorPayoutProductionService).
 *
 *   closed         → partially_paid / paid   registrar pagamento (parcial ou o total)
 *   partially_paid → partially_paid / paid   outro pagamento
 *   partially_paid / paid → partially_paid / closed
 *                                            estornar um pagamento (admin ou
 *                                            financeiro, com motivo)
 *   closed         → cancelled               reabrir (admin, com motivo; só
 *                                            sem pagamento válido; itens voltam
 *                                            a pendente)
 *
 * Fechamento nunca é apagado: cancelado fica no histórico (e o lançamento de
 * caixa que o referenciou continua auditável).
 */
enum DoctorPayoutStatus: string
{
    case Closed        = 'closed';
    case PartiallyPaid = 'partially_paid';
    case Paid          = 'paid';
    case Cancelled     = 'cancelled';

    public function label(): string
    {
        return __("financial_doctor_payouts.statuses.{$this->value}");
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Closed        => in_array($target, [self::PartiallyPaid, self::Paid, self::Cancelled], true),
            self::PartiallyPaid => in_array($target, [self::PartiallyPaid, self::Paid, self::Closed], true),
            self::Paid          => in_array($target, [self::PartiallyPaid, self::Closed], true),
            self::Cancelled     => false,
        };
    }

    /** Aceita novo pagamento (ainda há saldo a pagar). */
    public function acceptsPayment(): bool
    {
        return $this === self::Closed || $this === self::PartiallyPaid;
    }

    /** Tem pagamento válido (pode estornar; não reabre nem aceita ajuste). */
    public function hasPayments(): bool
    {
        return $this === self::PartiallyPaid || $this === self::Paid;
    }

    /**
     * Fechamentos válidos (não cancelados): prendem os itens, contam como
     * período fechado e aparecem para o médico.
     *
     * @return list<string>
     */
    public static function valid(): array
    {
        return [self::Closed->value, self::PartiallyPaid->value, self::Paid->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
