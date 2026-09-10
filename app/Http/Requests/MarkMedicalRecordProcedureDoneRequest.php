<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\{EntityProduct, StockLot};
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Confirmação de execução de App\Models\MedicalRecordProcedure + consumo de
 * estoque OPCIONAL associado. `items` pode vir vazio/ausente — procedimento
 * sem material físico consumido (ex.: consulta) só marca executado, sem
 * baixa nenhuma.
 *
 * Cada item é sempre SAÍDA (consumptionOut) — nunca cria lote novo aqui
 * (`stock_lot_id` só aceita lote JÁ EXISTENTE, mesma regra de saída da
 * StockMovementRequest: não dá pra "inventar" lote tirando estoque dele).
 * Produto `requires_lot=true` sem `stock_lot_id` é rejeitado no
 * `withValidator()` abaixo — mesmo princípio de defesa em profundidade,
 * repetido de novo dentro do service (StockService::registerMovement()
 * lança LotRequiredException independente da validação HTTP).
 */
class MarkMedicalRecordProcedureDoneRequest extends FormRequest
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
            'notes'                     => ['nullable', 'string', 'max:2000'],
            'items'                     => ['nullable', 'array'],
            'items.*.entity_product_id' => [
                'required_with:items',
                'uuid',
                Rule::exists('entity_products', 'id')
                    ->where('entity_id', $entityId)
                    ->whereNull('deleted_at'),
            ],
            'items.*.quantity'     => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.stock_lot_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.entity_product_id.required_with' => trans('validation.custom.generic.required'),
            'items.*.quantity.required_with'          => trans('validation.custom.generic.required'),
            'items.*.quantity.gt'                     => __('stock.quantity_must_be_positive'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $entityId = session('selected_entity_id');
            $items    = $this->input('items', []);

            foreach ($items as $index => $item) {
                if ($validator->errors()->has("items.{$index}.entity_product_id")) {
                    continue; // produto já inválido — não empilha erro de lote em cima
                }

                $product = EntityProduct::query()
                    ->where('entity_id', $entityId)
                    ->find($item['entity_product_id'] ?? null);

                if ($product === null) {
                    continue;
                }

                $lotId = $item['stock_lot_id'] ?? null;

                if ($product->requires_lot && blank($lotId)) {
                    $validator->errors()->add("items.{$index}.stock_lot_id", __('stock.lot_required', ['product' => $product->name]));

                    continue;
                }

                if (filled($lotId)) {
                    $lotExists = StockLot::query()
                        ->where('entity_id', $entityId)
                        ->where('entity_product_id', $product->id)
                        ->whereNull('deleted_at')
                        ->whereKey($lotId)
                        ->exists();

                    if (! $lotExists) {
                        $validator->errors()->add("items.{$index}.stock_lot_id", __('stock.lot_not_found'));
                    }
                }
            }
        });
    }

    /**
     * @return list<array{entity_product_id: string, quantity: float, stock_lot_id: ?string}>
     */
    public function items(): array
    {
        return collect($this->input('items', []))
            ->map(fn (array $item) => [
                'entity_product_id' => $item['entity_product_id'],
                'quantity'          => (float) $item['quantity'],
                'stock_lot_id'      => $item['stock_lot_id'] ?? null,
            ])
            ->all();
    }
}
