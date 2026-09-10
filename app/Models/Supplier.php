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
 * Fornecedor DA CLÍNICA (Fase 4 — compras). Catálogo simples, sem versão
 * global (diferente de Procedure/IolLensModel) — fornecedor não é
 * compartilhado entre clínicas.
 */
class Supplier extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasEntityCode;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'FOR';

    protected string $codePrefixGlobal = 'FORP';

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'document',
        'email',
        'phone',
        'contact_name',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
