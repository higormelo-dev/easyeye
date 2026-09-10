<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\{EntityProduct, StockLot};
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lançada quando uma saída de estoque (consumo/ajuste/perda/devolução)
 * resultaria em saldo negativo — do PRODUTO (agregado) ou de um LOTE
 * específico (`$lot` presente: o produto até tem saldo agregado suficiente,
 * mas ESTE lote não — ex.: pedir 10 do lote X que só tem 3, mesmo o produto
 * tendo 50 espalhados em outros lotes. Fisicamente correto: não dá pra usar
 * unidade de um lote diferente do informado). Bloqueado em
 * App\Services\Stock\StockService::registerMovement(), independente de qual
 * tela/fluxo chamou o service.
 *
 * Renderável (mesmo padrão de FeatureDeniedException): 422 pra
 * requisições JSON/Inertia, back() com erro pra fluxo web tradicional.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly EntityProduct $product,
        public readonly float $requested,
        public readonly float $available,
        public readonly ?StockLot $lot = null,
    ) {
        $target = $lot !== null ? "{$product->name} (lote {$lot->lot_number})" : $product->name;

        parent::__construct(
            "Saldo insuficiente para {$target}: solicitado {$requested}, disponível {$available}.",
        );
    }

    public function render(Request $request): Response
    {
        $message = $this->lot !== null
            ? __('stock.insufficient_lot_balance', [
                'product'   => $this->product->name,
                'lot'       => $this->lot->lot_number,
                'requested' => $this->requested,
                'available' => $this->available,
            ])
            : __('stock.insufficient_balance', [
                'product'   => $this->product->name,
                'requested' => $this->requested,
                'available' => $this->available,
            ]);

        // Mesmo padrão de FeatureDeniedException::render(): NÃO ramificar em
        // X-Inertia aqui — Inertia (useForm) envia XHR sem Accept JSON e
        // espera um redirect-back com erros de validação (protocolo
        // Inertia), não um corpo JSON solto; devolver JSON pra esse caso
        // quebraria o form.errors no client. expectsJson() cobre só
        // chamadas de API pura.
        if ($request->expectsJson()) {
            return response()->json([
                'message'   => $message,
                'product'   => $this->product->only(['id', 'name', 'code']),
                'lot'       => $this->lot?->only(['id', 'lot_number']),
                'requested' => $this->requested,
                'available' => $this->available,
            ], 422);
        }

        return back()->withErrors(['quantity' => $message]);
    }
}
