<?php

declare(strict_types=1);

namespace App\Events\Billing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A fatura foi devolvida por INTEIRO ao pagador: estorno total (`refund`) ou chargeback (`chargeback`) da cobrança que a
 * quitou. Nunca no estorno parcial nem no pagamento em duplicidade (a fatura segue paga pela outra cobrança). Quem escuta
 * é a NFS-e do SaaS (CancelNfseOnInvoiceFullyReversed): nota autorizada de fatura devolvida por inteiro é cancelada.
 *
 * ShouldDispatchAfterCommit: só anuncia o que foi gravado (rollback não avisa nada). Só ids — sem dado do pagador.
 */
class InvoiceFullyReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const REFUND = 'refund';

    public const CHARGEBACK = 'chargeback';

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $reason,
        public readonly ?string $correlationId = null,
    ) {
    }
}
