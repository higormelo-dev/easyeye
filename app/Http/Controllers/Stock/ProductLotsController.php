<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockLotRequest;
use App\Models\{EntityProduct, StockLot};
use Illuminate\Http\JsonResponse;

/**
 * Metadado de lotes (App\Models\StockLot) — número/validade/ativo. Saldo
 * (qty_on_hand/cost_avg) NUNCA passa por aqui, só por
 * App\Services\Stock\StockService (via lançamento de movimentação).
 *
 * Puro JSON (mesmo padrão de NoticesController) — chamado via
 * window.axios de dentro do offcanvas de edição do produto, sem navegação
 * de página Inertia (evita reload da listagem inteira só pra editar 1 lote).
 *
 * Isolamento OBRIGATÓRIO: index filtra por entity_id da sessão E pelo
 * produto informado (um lote nunca aparece fora do produto dono); update
 * re-checa posse pelo entity_id do LOTE resolvido via route model binding
 * (nunca confiar só no binding pra isolamento entre clínicas).
 */
class ProductLotsController extends Controller
{
    public function index(EntityProduct $entityProduct): JsonResponse
    {
        $this->assertOwnsProduct($entityProduct);

        $lots = $entityProduct->lots()
            ->orderBy('expiry_date')
            ->orderBy('lot_number')
            ->get()
            ->map(fn (StockLot $lot) => $this->serialize($lot));

        return response()->json(['data' => $lots]);
    }

    public function update(StockLotRequest $request, StockLot $stockLot): JsonResponse
    {
        $this->assertOwnsLot($stockLot);

        $stockLot->update($request->validated());

        return response()->json([
            'message' => __('stock.lot_updated'),
            'data'    => $this->serialize($stockLot->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(StockLot $lot): array
    {
        return [
            'id'             => $lot->id,
            'lot_number'     => $lot->lot_number,
            'expiry_date'    => $lot->expiry_date?->format('Y-m-d'),
            'cost_avg'       => (float) $lot->cost_avg,
            'qty_on_hand'    => (float) $lot->qty_on_hand,
            'active'         => (bool) $lot->active,
            'is_expired'     => $lot->isExpired(),
            'days_to_expiry' => $lot->daysUntilExpiry(),
        ];
    }

    private function assertOwnsProduct(EntityProduct $entityProduct): void
    {
        abort_unless(
            (string) $entityProduct->entity_id === (string) session('selected_entity_id'),
            404,
        );
    }

    private function assertOwnsLot(StockLot $stockLot): void
    {
        abort_unless(
            (string) $stockLot->entity_id === (string) session('selected_entity_id'),
            404,
        );
    }
}
