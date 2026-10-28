<?php

namespace App\DTOs\Billing;

/**
 * Cobrança de uma recorrência no gateway. Situação normalizada: paid
 * (recebida/confirmada), pending (a vencer), overdue (vencida sem
 * pagamento) ou other (estornada, em disputa, cancelada...). Datas em Y-m-d.
 */
readonly class GatewayRecurrenceChargeDTO
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const OVERDUE = 'overdue';

    public const OTHER = 'other';

    public function __construct(
        public string $id,
        public string $status,
        public string $rawStatus,
        public string $dueDate,
        public ?string $paidOn,
        public ?float $amount,
        public ?string $paymentUrl,
    ) {
    }
}
