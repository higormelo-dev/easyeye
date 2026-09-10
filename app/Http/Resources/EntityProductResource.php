<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntityProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'code'                => $this->code,
            'sku'                 => $this->sku,
            'name'                => $this->name,
            'description'         => $this->description,
            'unit'                => $this->unit?->value,
            'unit_label'          => $this->unit?->label(),
            'product_category_id' => $this->product_category_id,
            'category_name'       => $this->whenLoaded('category', fn () => $this->category?->name),
            'is_opm'              => (bool) $this->is_opm,
            'requires_lot'        => (bool) $this->requires_lot,
            'cost_avg'            => (float) $this->cost_avg,
            'sale_price'          => $this->sale_price !== null ? (float) $this->sale_price : null,
            'min_qty'             => (float) $this->min_qty,
            'max_qty'             => $this->max_qty !== null ? (float) $this->max_qty : null,
            'qty_on_hand'         => (float) $this->qty_on_hand,
            'below_minimum'       => $this->isBelowMinimum(),
            // Baseado na relação `lots` (quando eager-loaded já filtrada por
            // active()+withBalance() — ver ProductsController::index()); sem
            // eager load, `lots` dispara 1 query por produto (N+1) — ok pro
            // volume de um show() avulso, evitar em listagens sem with().
            'nearest_expiry' => $this->whenLoaded('lots', fn () => $this->lots
                ->sortBy('expiry_date')
                ->first()?->expiry_date?->format('d/m/Y')),
            'has_expiring_lot' => $this->whenLoaded('lots', fn () => $this->lots
                ->contains(fn ($lot) => $lot->expiry_date !== null && $lot->expiry_date->lte(now()->addDays(30)))),
            'active'     => (bool) $this->active,
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
