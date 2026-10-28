<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirmação de contagem física de estoque (GAP fechado — revisão pós-
 * Fase 4, "melhorar o módulo de estoque"): antes só dava pra ajustar saldo
 * PRODUTO A PRODUTO na tela de Movimentação; aqui o usuário conta MUITOS
 * produtos de uma vez (lista completa com o saldo do sistema ao lado) e
 * envia tudo junto — o service (StockService::adjustToCountedQuantity())
 * já ignora sozinho item cuja contagem bate com o sistema, sem gerar
 * movimentação à toa (ver docblock do método).
 *
 * Contagem é POR PRODUTO (agregado), não por lote — separar por lote
 * físico na hora de contar é operação bem mais lenta e não é o pedido
 * original; produto `requires_lot=true` continua rastreável via
 * StockMovement.stock_lot_id nas entradas/consumos normais, só a
 * CONTAGEM em massa fica no nível de produto (v1).
 */
class StockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entityId = session('selected_entity_id');

        return [
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.entity_product_id' => [
                'required',
                'uuid',
                Rule::exists('entity_products', 'id')
                    ->where('entity_id', $entityId)
                    ->whereNull('deleted_at'),
            ],
            'items.*.counted_qty' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required'                     => trans('validation.custom.generic.required'),
            'items.*.entity_product_id.required' => trans('validation.custom.generic.required'),
            'items.*.counted_qty.required'       => trans('validation.custom.generic.required'),
        ];
    }

    /**
     * @return list<array{entity_product_id: string, counted_qty: float}>
     */
    public function items(): array
    {
        return collect($this->input('items', []))
            ->map(fn (array $item) => [
                'entity_product_id' => $item['entity_product_id'],
                'counted_qty'       => (float) $item['counted_qty'],
            ])
            ->all();
    }
}
