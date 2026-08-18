<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Support\Facades\Storage;

/**
 * Catálogo GLOBAL de modelos de lente intraocular (IOL/catarata) —
 * fabricante + modelo + foto de produto, sem escopo por entity_id
 * (intencional: todas as clínicas compartilham/reaproveitam). Cresce
 * organicamente via IolLensCatalogService::findOrCreateModel().
 *
 * Para o inventário POR CLÍNICA (com preço, dioptria, estoque), ver
 * EntityIolLens.
 */
class IolLensModel extends Model
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
        'manufacturer',
        'model_name',
        'category',
        'normalized_key',
        'image_path',
        'created_by_entity_id',
    ];

    /**
     * @var string[]
     */
    protected $appends = ['image_url'];

    /**
     * Entidade (clínica) que cadastrou este modelo primeiro no catálogo
     * global; null quando o registro veio de seed do sistema.
     */
    public function createdByEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'created_by_entity_id');
    }

    /**
     * Busca por fabricante ou modelo (case-insensitive, parcial).
     * Ordena: correspondências no início do fabricante/modelo primeiro,
     * depois alfabético. Mesmo padrão de Cid10Code::scopeSearch().
     */
    public function scopeSearch($query, string $term): void
    {
        $lower = mb_strtolower(trim($term), 'UTF-8');

        $query->where(function ($q) use ($lower) {
            $q->whereRaw('LOWER(manufacturer) LIKE ?', ["%{$lower}%"])
                ->orWhereRaw('LOWER(model_name) LIKE ?', ["%{$lower}%"]);
        })->orderByRaw(
            'CASE WHEN LOWER(manufacturer) LIKE ? OR LOWER(model_name) LIKE ? THEN 0 ELSE 1 END',
            ["{$lower}%", "{$lower}%"],
        )
            ->orderBy('manufacturer')
            ->orderBy('model_name');
    }

    public function imageUrl(): Attribute
    {
        return new Attribute(
            get: fn () => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        );
    }
}
