<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Ciclo de vida de um fechamento de repasse (App\Models\DoctorPayout).
 *
 * "Pendente" não é um status gravado: é a produção ainda fora de qualquer
 * fechamento válido, calculada ao vivo (DoctorPayoutProductionService).
 *
 *   closed → paid       registrar pagamento
 *   paid   → closed     estornar pagamento (admin, com motivo)
 *   closed → cancelled  reabrir (admin, com motivo; itens voltam a pendente)
 *
 * Fechamento nunca é apagado: cancelado fica no histórico (e o lançamento de
 * caixa que o referenciou continua auditável).
 */
enum DoctorPayoutStatus: string
{
    case Closed    = 'closed';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("financial_doctor_payouts.statuses.{$this->value}");
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Closed    => in_array($target, [self::Paid, self::Cancelled], true),
            self::Paid      => $target === self::Closed,
            self::Cancelled => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
