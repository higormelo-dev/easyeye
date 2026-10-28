<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\EntityProduct;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lançada quando App\Services\Stock\StockService recebe um movimento pra um
 * produto `requires_lot=true` SEM um lote informado. Regra de NEGÓCIO,
 * aplicada no service — a UI também bloqueia, mas o service nunca confia
 * só nisso (mesmo princípio de InsufficientStockException).
 */
class LotRequiredException extends RuntimeException
{
    public function __construct(
        public readonly EntityProduct $product,
    ) {
        parent::__construct(
            "Produto [{$product->name}] exige lote — informe stock_lot_id (ou lot_number pra criar um novo).",
        );
    }

    public function render(Request $request): Response
    {
        $message = __('stock.lot_required', ['product' => $this->product->name]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'product' => $this->product->only(['id', 'name', 'code']),
            ], 422);
        }

        return back()->withErrors(['stock_lot_id' => $message]);
    }
}
