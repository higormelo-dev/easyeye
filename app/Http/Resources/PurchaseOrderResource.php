<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'code'                   => $this->code,
            'supplier_id'            => $this->supplier_id,
            'supplier_name'          => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'status'                 => $this->status->value,
            'status_label'           => $this->status->label(),
            'is_editable'            => $this->status->isEditable(),
            'order_date'             => $this->order_date?->format('Y-m-d'),
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'notes'                  => $this->notes,
            'total_amount'           => (float) $this->total_amount,
            'received_at'            => $this->received_at?->format('d/m/Y H:i'),
            'items'                  => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id'                 => $item->id,
                'entity_product_id'  => $item->entity_product_id,
                'product_name'       => $item->product?->name,
                'product_code'       => $item->product?->code,
                'unit_label'         => $item->product?->unit?->label(),
                'requires_lot'       => (bool) $item->product?->requires_lot,
                'quantity_ordered'   => (float) $item->quantity_ordered,
                'quantity_received'  => (float) $item->quantity_received,
                'remaining_quantity' => $item->remainingQuantity(),
                'unit_cost'          => (float) $item->unit_cost,
                'subtotal'           => (float) $item->subtotal,
            ])->values()),
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
