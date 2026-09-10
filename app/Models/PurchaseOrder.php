<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Pedido de compra (Fase 4). Ver doc da migration `create_purchase_orders_table`.
 *
 * `total_amount` é denormalizado — só App\Services\Stock\PurchaseOrderService
 * escreve nele (recalculado a cada mudança de item), fora de $fillable.
 */
class PurchaseOrder extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'PC';

    protected string $codePrefixGlobal = 'PCP';

    protected $fillable = [
        'entity_id',
        'supplier_id',
        'code',
        'status',
        'order_date',
        'expected_delivery_date',
        'notes',
        'received_at',
    ];

    /**
     * Default EM PHP, não só no banco — mesma lição da Fase 3
     * (MedicalRecordProcedure::$attributes): sem isso, um `create([...])`
     * que não passa 'status'/'total_amount' explicitamente deixa o objeto
     * em memória com esses atributos AUSENTES até um `fresh()`, e
     * `$po->status->canTransitionTo(...)` logo em seguida quebraria com
     * "member function on null".
     */
    protected $attributes = [
        'status'       => 'draft',
        'total_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status'                 => PurchaseOrderStatus::class,
            'order_date'             => 'date',
            'expected_delivery_date' => 'date',
            'received_at'            => 'datetime',
            'total_amount'           => 'decimal:2',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Movimentos de estoque gerados pelo recebimento — ver StockMovement.reference_*. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled]);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
