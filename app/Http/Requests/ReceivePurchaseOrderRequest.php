<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\{EntityProduct, PurchaseOrderItem, StockLot};
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Recebimento (total ou parcial) de um pedido de compra —
 * App\Services\Stock\PurchaseOrderService::receive(). Mesma regra de lote
 * de StockMovementRequest: `stock_lot_id` (existente) OU `new_lot_number`
 * (cria na hora — recebimento É sempre entrada, então SEMPRE pode criar
 * lote novo aqui, diferente do consumo em MedicalRecordProcedure que só
 * aceita lote existente).
 */
class ReceivePurchaseOrderRequest extends FormRequest
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
        $poId     = $this->route('purchaseOrder')?->id;

        return [
            'items'                          => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => [
                'required',
                'uuid',
                Rule::exists('purchase_order_items', 'id')
                    ->where('entity_id', $entityId)
                    ->where('purchase_order_id', $poId),
            ],
            'items.*.quantity'     => ['required', 'numeric', 'gt:0'],
            'items.*.stock_lot_id' => [
                'nullable',
                'uuid',
                Rule::exists('stock_lots', 'id')->where('entity_id', $entityId)->whereNull('deleted_at'),
            ],
            'items.*.new_lot_number'      => ['nullable', 'string', 'max:100'],
            'items.*.new_lot_expiry_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required'                          => trans('validation.custom.generic.required'),
            'items.*.purchase_order_item_id.required' => trans('validation.custom.generic.required'),
            'items.*.quantity.gt'                     => __('stock.quantity_must_be_positive'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('items', []) as $index => $item) {
                if ($validator->errors()->has("items.{$index}.purchase_order_item_id")) {
                    continue;
                }

                $hasExistingLot = filled($item['stock_lot_id'] ?? null);
                $hasNewLot      = filled($item['new_lot_number'] ?? null);

                if ($hasExistingLot && $hasNewLot) {
                    $validator->errors()->add("items.{$index}.stock_lot_id", __('stock.lot_pick_one'));

                    continue;
                }

                $poItem = PurchaseOrderItem::query()->find($item['purchase_order_item_id'] ?? null);

                if ($poItem === null) {
                    continue;
                }

                /** @var EntityProduct|null $product */
                $product = $poItem->product;

                if ($product !== null && $product->requires_lot && ! $hasExistingLot && ! $hasNewLot) {
                    $validator->errors()->add("items.{$index}.stock_lot_id", __('stock.lot_required', ['product' => $product->name]));
                }

                if ($hasExistingLot) {
                    $lotOk = StockLot::query()
                        ->where('entity_id', session('selected_entity_id'))
                        ->where('entity_product_id', $product?->id)
                        ->whereNull('deleted_at')
                        ->whereKey($item['stock_lot_id'])
                        ->exists();

                    if (! $lotOk) {
                        $validator->errors()->add("items.{$index}.stock_lot_id", __('stock.lot_not_found'));
                    }
                }
            }
        });
    }

    /**
     * @return list<array{purchase_order_item_id: string, quantity: float, stock_lot_id: ?string, new_lot_number: ?string, new_lot_expiry_date: ?Carbon}>
     */
    public function items(): array
    {
        return collect($this->input('items', []))
            ->map(fn (array $item) => [
                'purchase_order_item_id' => $item['purchase_order_item_id'],
                'quantity'               => (float) $item['quantity'],
                'stock_lot_id'           => $item['stock_lot_id'] ?? null,
                'new_lot_number'         => $item['new_lot_number'] ?? null,
                'new_lot_expiry_date'    => filled($item['new_lot_expiry_date'] ?? null) ? Carbon::parse($item['new_lot_expiry_date']) : null,
            ])
            ->all();
    }
}
