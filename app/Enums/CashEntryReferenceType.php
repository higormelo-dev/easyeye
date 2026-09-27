<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\PurchaseOrder;

/**
 * Whitelist dos vínculos de sistema de um lançamento de caixa
 * (financial_cash_entries.reference_type / reference_id).
 *
 * A referência é SEMPRE definida pelo backend — nunca pelo cliente:
 *  - Schedule      : recebimento na chegada (CashFlowService::createForSchedule);
 *                    lido pela agenda (has_cash_entry), trava de duplicidade e
 *                    regra "Atendido exige caixa".
 *  - BillingClaim  : recebimento de guia (BillingService::markClaimPaid), gravado
 *                    junto com billing_claim_id = reference_id.
 *  - PurchaseOrder : despesa do recebimento de compra (PurchaseOrdersController::receive).
 *                    Guarda o FQCN por compatibilidade com linhas já gravadas
 *                    (mesmo desenho de stock_movements.reference_type).
 *
 * Não é um morph map global: AuditLog/RecordVersion/DataAccessLog/
 * PatientDocumentShare continuam gravando FQCN nas próprias colunas polimórficas.
 */
enum CashEntryReferenceType: string
{
    case Schedule      = 'schedule';
    case BillingClaim  = 'billing_claim';
    case PurchaseOrder = PurchaseOrder::class;

    /** Tabela do registro referenciado (todas com uuid `id` + `entity_id`). */
    public function table(): string
    {
        return match ($this) {
            self::Schedule      => 'schedules',
            self::BillingClaim  => 'billing_claims',
            self::PurchaseOrder => 'purchase_orders',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
