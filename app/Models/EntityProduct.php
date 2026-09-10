<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Enums\StockUnit;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Item de estoque DA CLÍNICA (produto/material/insumo/OPM) — escopado por
 * entity_id.
 *
 * IMPORTANTE: `qty_on_hand` e `cost_avg` ficam FORA de $fillable de propósito
 * — são saldo/custo médio mantidos exclusivamente por
 * App\Services\Stock\StockService sob lock de linha. Nenhum controller deve
 * escrever esses dois campos via mass-assignment (create/update); a única
 * forma correta de alterar saldo é StockService::entrada()/saida()/ajuste(),
 * que gera a movimentação em stock_movements e atualiza este model.
 */
class EntityProduct extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasEntityCode;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'PRD';

    protected string $codePrefixGlobal = 'PRDP';

    protected $fillable = [
        'entity_id',
        'product_category_id',
        'code',
        'sku',
        'name',
        'description',
        'unit',
        'is_opm',
        'requires_lot',
        'sale_price',
        'min_qty',
        'max_qty',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'unit'         => StockUnit::class,
            'is_opm'       => 'boolean',
            'requires_lot' => 'boolean',
            'active'       => 'boolean',
            'cost_avg'     => 'decimal:4',
            'sale_price'   => 'decimal:2',
            'min_qty'      => 'decimal:3',
            'max_qty'      => 'decimal:3',
            'qty_on_hand'  => 'decimal:3',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    /**
     * Itens do inventário de lentes IOL vinculados a este produto (vínculo
     * opcional/aditivo — ver docblock de App\Models\EntityIolLens). Normal
     * um produto não ter nenhum lens vinculado.
     */
    public function iolLenses(): HasMany
    {
        return $this->hasMany(EntityIolLens::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * True = saldo no ou abaixo do mínimo CONFIGURADO (alerta de reposição).
     *
     * BUGFIX (achado na revisão de gaps): `min_qty` nasce 0 por padrão pra
     * todo produto (coluna `default(0)`, ninguém configurou um mínimo
     * ainda) — sem o `where('min_qty', '>', 0)`, TODO produto recém-
     * cadastrado (qty_on_hand=0, min_qty=0) batia `0 <= 0` e entrava como
     * "abaixo do mínimo" antes mesmo de alguém decidir que ele TEM um
     * mínimo. min_qty=0 significa "sem controle de mínimo configurado",
     * não "mínimo é zero unidades" — false positive silencioso desde a
     * Fase 1 (badge da tela de Produtos e, agora, StockAlertService).
     */
    public function scopeBelowMinimum($query)
    {
        return $query->where('min_qty', '>', 0)->whereColumn('qty_on_hand', '<=', 'min_qty');
    }

    /** Produtos com pelo menos um lote (não expirado) vencendo nos próximos N dias. */
    public function scopeWithExpiringLots($query, int $days = 30)
    {
        return $query->whereHas('lots', fn ($q) => $q->active()->withBalance()->expiringWithin($days));
    }

    /** Mesma regra de scopeBelowMinimum() — ver bugfix ali (min_qty=0 = sem controle configurado). */
    public function isBelowMinimum(): bool
    {
        return (float) $this->min_qty > 0 && (float) $this->qty_on_hand <= (float) $this->min_qty;
    }
}
