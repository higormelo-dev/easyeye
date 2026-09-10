<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Lote/validade de um item de estoque — rastreabilidade sanitária pra
 * produtos com `requires_lot=true` (materiais especiais/OPM).
 *
 * IMPORTANTE: `qty_on_hand`/`cost_avg` ficam FORA de $fillable — mesma regra
 * de EntityProduct, só App\Services\Stock\StockService escreve (sob lock).
 * Cadastro/edição de metadado do lote (número, validade, ativo) passa por
 * $fillable normalmente; SALDO do lote não.
 */
class StockLot extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'entity_product_id',
        'lot_number',
        'expiry_date',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'cost_avg'    => 'decimal:4',
            'qty_on_hand' => 'decimal:3',
            'active'      => 'boolean',
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

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Lotes com saldo > 0 — só esses fazem sentido pra seleção numa saída. */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->where('qty_on_hand', '>', 0);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')->where('expiry_date', '<', now()->toDateString());
    }

    /** Vence nos próximos N dias (inclui já vencidos, quem chama filtra se quiser separar). */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays($days)->toDateString());
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /** Null = sem validade cadastrada. Negativo = já venceu há N dias. */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->expiry_date, false);
    }
}
