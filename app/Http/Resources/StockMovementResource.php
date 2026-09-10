<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'entity_product_id' => $this->entity_product_id,
            'product_name'      => $this->whenLoaded('product', fn () => $this->product?->name),
            'product_code'      => $this->whenLoaded('product', fn () => $this->product?->code),
            'type'              => $this->type->value,
            'type_label'        => $this->type->label(),
            'direction'         => $this->type->direction(),
            'quantity'          => (float) $this->quantity,
            'unit_cost'         => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'balance_after'     => (float) $this->balance_after,
            'lot_number'        => $this->whenLoaded('lot', fn () => $this->lot?->lot_number),
            'note'              => $this->note,
            'created_by_name'   => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'occurred_at'       => $this->occurred_at?->format('d/m/Y H:i'),
            'created_at'        => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
