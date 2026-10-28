<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Categoria de produto/material de estoque DA CLÍNICA (ex.: "Colírios",
 * "Materiais cirúrgicos", "OPM"). Catálogo simples — mesmo padrão de
 * AdditionType/VisitType.
 */
class ProductCategory extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasEntityCode;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'CAT';

    protected string $codePrefixGlobal = 'CATP';

    protected $fillable = ['entity_id', 'code', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(EntityProduct::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
