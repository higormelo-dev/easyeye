<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Estorno de consumo recusado: o movimento não é um consumo, não pertence à
 * clínica do produto ou o produto/lote não confere. Nada é gravado.
 */
class StockReversalException extends RuntimeException
{
    public function render(Request $request): Response
    {
        $message = __('stock.reversal_refused');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors(['stock_movement_id' => $message]);
    }
}
