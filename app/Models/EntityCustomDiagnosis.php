<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class EntityCustomDiagnosis extends Model
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
        'name',
        'normalized_name',
        'cid10_code_id',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function cid10Code(): BelongsTo
    {
        return $this->belongsTo(Cid10Code::class, 'cid10_code_id');
    }

    /**
     * Busca por nome (case-insensitive, parcial).
     */
    public function scopeSearch($query, string $term): void
    {
        $lower = mb_strtolower(trim($term), 'UTF-8');

        $query->whereRaw('LOWER(name) LIKE ?', ["%{$lower}%"]);
    }
}
