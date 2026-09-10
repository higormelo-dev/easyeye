<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha IMUTÁVEL do ledger de estoque. Nunca editar/apagar uma instância
 * existente — ver doc da migration `create_stock_movements_table` e
 * App\Services\Stock\StockService (único ponto de escrita desta tabela).
 *
 * Sem SoftDeletes de propósito: um movimento não se apaga, se estorna
 * (nova linha em sentido oposto).
 */
class StockMovement extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'entity_product_id',
        'stock_lot_id',
        'type',
        'quantity',
        'unit_cost',
        'balance_after',
        'reference_type',
        'reference_id',
        'note',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type'          => StockMovementType::class,
            'quantity'      => 'decimal:3',
            'unit_cost'     => 'decimal:4',
            'balance_after' => 'decimal:3',
            'occurred_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EntityProduct::class, 'entity_product_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
