<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de movimentação de estoque — cada linha em `stock_movements` é
 * imutável (ledger de auditoria); uma correção nunca edita/apaga uma
 * movimentação, sempre lança uma nova em sentido oposto (ver
 * App\Services\Stock\StockService).
 */
enum StockMovementType: string
{
    case PurchaseIn     = 'purchase_in';    // entrada por compra/recebimento de fornecedor
    case ManualIn       = 'manual_in';      // entrada manual (doação, transferência recebida, correção)
    case ConsumptionOut = 'consumption_out'; // saída por consumo em procedimento/cirurgia
    case ManualOut      = 'manual_out';     // saída manual (uso avulso não ligado a procedimento)
    case AdjustmentIn   = 'adjustment_in';   // ajuste de balanço (contagem física > saldo sistema)
    case AdjustmentOut  = 'adjustment_out';  // ajuste de balanço (contagem física < saldo sistema)
    case Loss           = 'loss';           // perda/quebra/vencimento
    case ReturnOut      = 'return_out';     // devolução ao fornecedor

    public function label(): string
    {
        return match ($this) {
            self::PurchaseIn     => 'Entrada por compra',
            self::ManualIn       => 'Entrada manual',
            self::ConsumptionOut => 'Consumo em procedimento',
            self::ManualOut      => 'Saída manual',
            self::AdjustmentIn   => 'Ajuste de balanço (entrada)',
            self::AdjustmentOut  => 'Ajuste de balanço (saída)',
            self::Loss           => 'Perda/quebra',
            self::ReturnOut      => 'Devolução a fornecedor',
        };
    }

    /** +1 soma ao saldo, -1 subtrai. Única fonte de verdade da direção do movimento. */
    public function direction(): int
    {
        return match ($this) {
            self::PurchaseIn, self::ManualIn, self::AdjustmentIn => 1,
            self::ConsumptionOut, self::ManualOut, self::AdjustmentOut, self::Loss, self::ReturnOut => -1,
        };
    }

    /** True = tipo de ENTRADA (recalcula custo médio ponderado). */
    public function isInbound(): bool
    {
        return $this->direction() === 1;
    }

    /**
     * Tipos que um usuário pode lançar manualmente pela tela de movimentação
     * (Panel/Stock/Movements). `purchase_in` fica de fora aqui — na Fase 4
     * (compras) ele nasce do recebimento de um pedido de compra, não de
     * lançamento livre; `consumption_out` fica de fora porque nasce da baixa
     * automática do prontuário (Fase 3), não de lançamento livre.
     *
     * @return list<self>
     */
    public static function manualTypes(): array
    {
        return [self::ManualIn, self::ManualOut, self::AdjustmentIn, self::AdjustmentOut, self::Loss, self::ReturnOut];
    }
}
