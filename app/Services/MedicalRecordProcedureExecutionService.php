<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MedicalRecordProcedureStatus;
use App\Models\{EntityProduct, EntityUser, MedicalRecordProcedure, StockLot};
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Transição de estado de App\Models\MedicalRecordProcedure + consumo de
 * estoque opcional associado (Fase 3 — estoque ↔ prontuário).
 *
 * markDone() é ATÔMICO: status do procedimento + TODAS as baixas de
 * estoque (uma por item) commitam juntas ou nenhuma commita. Se o item 3
 * de 5 falhar (ex.: saldo insuficiente daquele lote), os itens 1-2 também
 * são desfeitos e o procedimento continua `requested` — nunca fica em
 * estado parcial (metade do material baixado, procedimento "meio
 * executado"). DB::transaction() aninha via savepoint com a transação
 * própria de cada StockService::consumptionOut() — mesmo padrão já usado
 * em StockService::adjustToCountedQuantity().
 */
class MedicalRecordProcedureExecutionService
{
    public function __construct(
        private readonly StockService $stockService,
    ) {
    }

    /**
     * @param list<array{entity_product_id: string, quantity: float, stock_lot_id: ?string}> $items
     */
    public function markDone(
        MedicalRecordProcedure $procedure,
        EntityUser $executedBy,
        array $items = [],
        ?string $notes = null,
    ): MedicalRecordProcedure {
        $this->assertTransition($procedure, MedicalRecordProcedureStatus::Done);

        return DB::transaction(function () use ($procedure, $executedBy, $items, $notes) {
            foreach ($items as $item) {
                $product = EntityProduct::query()
                    ->where('entity_id', $procedure->entity_id)
                    ->findOrFail($item['entity_product_id']);

                $lot = null;

                if (! empty($item['stock_lot_id'])) {
                    $lot = StockLot::query()
                        ->where('entity_id', $procedure->entity_id)
                        ->findOrFail($item['stock_lot_id']);
                }

                $this->stockService->consumptionOut(
                    product: $product,
                    quantity: $item['quantity'],
                    note: "Consumo em procedimento: {$procedure->procedure?->name}",
                    referenceType: MedicalRecordProcedure::class,
                    referenceId: $procedure->id,
                    lot: $lot,
                );
            }

            $procedure->update([
                'status'      => MedicalRecordProcedureStatus::Done,
                'executed_at' => now(),
                'executed_by' => $executedBy->id,
                'notes'       => $notes ?? $procedure->notes,
            ]);

            return $procedure->fresh();
        });
    }

    public function cancel(MedicalRecordProcedure $procedure, ?string $notes = null): MedicalRecordProcedure
    {
        $this->assertTransition($procedure, MedicalRecordProcedureStatus::Cancelled);

        $procedure->update([
            'status' => MedicalRecordProcedureStatus::Cancelled,
            'notes'  => $notes ?? $procedure->notes,
        ]);

        return $procedure->fresh();
    }

    private function assertTransition(MedicalRecordProcedure $procedure, MedicalRecordProcedureStatus $target): void
    {
        if (! $procedure->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "Procedimento está '{$procedure->status->label()}' — não pode virar '{$target->label()}'.",
            );
        }
    }
}
