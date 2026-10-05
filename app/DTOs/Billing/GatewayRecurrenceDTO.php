<?php

namespace App\DTOs\Billing;

use App\Enums\BillingCycle;

/**
 * Recorrência consultada no gateway (QueriesGatewayRecurrences). `cycle` nulo
 * = ciclo que o produto não vende (ex.: semanal); datas em Y-m-d.
 */
readonly class GatewayRecurrenceDTO
{
    /**
     * @param list<GatewayRecurrenceChargeDTO> $charges
     */
    public function __construct(
        public string $id,
        public bool $active,
        public string $status,
        public ?BillingCycle $cycle,
        public ?string $rawCycle,
        public ?float $amount,
        public ?string $nextDueDate,
        public array $charges = [],
    ) {
    }
}
