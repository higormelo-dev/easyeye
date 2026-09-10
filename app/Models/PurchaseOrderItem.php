<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de pedido de compra — ver doc da migration
 * `create_purchase_order_items_table`. `quantity_received` fora de
 * $fillable de propósito: só PurchaseOrderService::receive() escreve.
 */
class PurchaseOrderItem extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'purchase_order_id',
        'entity_product_id',
        'quantity_ordered',
        'unit_cost',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered'  => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_cost'         => 'decimal:4',
            'subtotal'          => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EntityProduct::class, 'entity_product_id');
    }

    public function remainingQuantity(): float
    {
        return round((float) $this->quantity_ordered - (float) $this->quantity_received, 3);
    }

    public function isFullyReceived(): bool
    {
        return $this->remainingQuantity() <= 0.0;
    }
}
