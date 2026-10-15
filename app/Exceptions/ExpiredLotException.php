<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\{EntityProduct, StockLot};
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumo de um lote com a validade vencida. Material vencido nunca é usado
 * em paciente: App\Services\Stock\StockService::consumptionOut() recusa
 * (a UI também esconde o lote, mas o service não confia só nisso). Perda,
 * ajuste e devolução NÃO passam por esta regra — baixar o lote vencido do
 * estoque é justamente o que se faz com ele.
 *
 * Validade vale até o fim do dia impresso no lote (mesmo critério de
 * StockLot::scopeExpired()).
 */
class ExpiredLotException extends RuntimeException
{
    public function __construct(
        public readonly EntityProduct $product,
        public readonly StockLot $lot,
    ) {
        parent::__construct("Lote {$lot->lot_number} de {$product->name} está vencido.");
    }

    public function render(Request $request): Response
    {
        $message = __('stock.lot_expired', [
            'product' => $this->product->name,
            'lot'     => $this->lot->lot_number,
            'date'    => $this->lot->expiry_date?->isoFormat('L'),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'product' => $this->product->only(['id', 'name', 'code']),
                'lot'     => $this->lot->only(['id', 'lot_number']),
            ], 422);
        }

        return back()->withErrors(['stock_lot_id' => $message]);
    }
}
