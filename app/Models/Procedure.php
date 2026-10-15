<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Procedure extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'nomo_binocular',
        'treatment',
        'clinical_kind',
        'active',
    ];

    /** Procedimento do catálogo que é injeção intravítrea (anti-VEGF): a execução dele alimenta o curso do olho. */
    public const KIND_INTRAVITREAL = 'intravitreal_injection';

    protected function casts(): array
    {
        return [
            'active'         => 'boolean',
            'nomo_binocular' => 'integer',
            'treatment'      => 'integer',
        ];
    }

    public function isIntravitreal(): bool
    {
        return $this->clinical_kind === self::KIND_INTRAVITREAL;
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** BOM de estoque (Fase 3) — ver App\Models\ProcedureProduct. */
    public function productBom(): HasMany
    {
        return $this->hasMany(ProcedureProduct::class);
    }
}
