<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preço de um procedimento para um convênio (portado do smart_oftal).
 * entity_id null = preço padrão de sistema; entity_id preenchido = override da clínica.
 */
class ProcedurePrice extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'covenant_id',
        'procedure_id',
        'price',
        'charging',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price'    => 'decimal:2',
            'charging' => 'boolean',
            'active'   => 'boolean',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
