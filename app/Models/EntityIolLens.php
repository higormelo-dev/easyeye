<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

/**
 * Metadado CLÍNICO/ÓPTICO de uma lente intraocular (IOL/catarata) POR
 * CLÍNICA — escopado por entity_id. Fabricante, nome/modelo, preço, foto e
 * status ativo NÃO vivem mais aqui: migraram pro produto de estoque
 * vinculado (App\Models\EntityProduct, ver entityProduct()) — este model
 * guarda só o que é exclusivamente clínico/óptico e não faz sentido em
 * nenhum outro tipo de produto: `category` (tipo ÓPTICO da lente —
 * monofocal/multifocal/tórica/EDF; NÃO confundir com
 * EntityProduct::product_category_id, que é a categoria AMPLA de estoque
 * "Lentes IOL") e a faixa de dioptria.
 *
 * `entity_product_id` é 1:1 OBRIGATÓRIO (NOT NULL + UNIQUE desde a migration
 * que aperta o schema) — toda lente É um produto de estoque real, criado/
 * atualizado sempre junto via App\Services\IolLensStockBridgeService.
 * Histórico: até a migration de aperto de schema, esse vínculo era
 * OPCIONAL (GAP-FILL pós-Fase 4 do módulo de estoque) e os campos acima
 * eram snapshot local duplicado — ver
 * database/migrations/2026_09_09_210000_add_entity_product_id_to_entity_iol_lenses_table.php
 * pro contexto da decisão original de não unificar, revertida neste plano.
 *
 * Para o catálogo GLOBAL de referência (sem escopo, compartilhado entre
 * clínicas), ver IolLensModel.
 */
class EntityIolLens extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'iol_lens_model_id',
        'entity_product_id',
        'category',
        'diopter_min',
        'diopter_max',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diopter_min' => 'decimal:2',
            'diopter_max' => 'decimal:2',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    /**
     * Modelo do catálogo global ao qual este item de inventário está
     * vinculado. Nullable — permite cadastro 100% customizado sem vínculo
     * com o catálogo global.
     */
    public function lensModel(): BelongsTo
    {
        return $this->belongsTo(IolLensModel::class, 'iol_lens_model_id');
    }

    /**
     * Produto de estoque desta lente — fabricante, nome, preço, foto, saldo
     * e status ativo vivem todos aqui agora. 1:1 obrigatório, sempre
     * presente (ver docblock da classe).
     */
    public function entityProduct(): BelongsTo
    {
        return $this->belongsTo(EntityProduct::class, 'entity_product_id');
    }

    /** Delega pro status ativo do produto de estoque vinculado. */
    public function scopeActive($query)
    {
        return $query->whereHas('entityProduct', fn ($q) => $q->where('active', true));
    }
}
