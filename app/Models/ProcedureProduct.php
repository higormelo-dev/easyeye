<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOM de estoque por procedimento (App\Models\Procedure → App\Models\
 * EntityProduct, com quantidade padrão). Ver doc da migration
 * `create_procedure_products_table` — é só SUGESTÃO de pré-preenchimento,
 * nunca baixa estoque sozinha.
 */
class ProcedureProduct extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'procedure_id',
        'entity_product_id',
        'quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EntityProduct::class, 'entity_product_id');
    }
}
