<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Support\Facades\Storage;

/**
 * Inventário de lentes intraoculares (IOL/catarata) POR CLÍNICA — escopado
 * por entity_id. manufacturer/model_name/category são snapshot local (cópia
 * estável, não depende de join com iol_lens_models pra exibir/buscar), mesmo
 * quando vinculado ao catálogo global via lensModel().
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
        'manufacturer',
        'model_name',
        'category',
        'diopter_min',
        'diopter_max',
        'price',
        'image_path',
        'active',
    ];

    /**
     * @var string[]
     */
    protected $appends = ['image_url'];

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
            'price'       => 'decimal:2',
            'active'      => 'boolean',
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
     * Foto própria da clínica (se houver); senão, cai pra foto do modelo do
     * catálogo global vinculado (se houver). Acessar lensModel sem eager
     * load em listagens gera N+1 — usar with('lensModel') quando aplicável.
     */
    public function imageUrl(): Attribute
    {
        return new Attribute(
            get: fn () => $this->image_path
                ? Storage::disk('public')->url($this->image_path)
                : $this->lensModel?->image_url,
        );
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
