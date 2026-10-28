<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class MedicinePresentation extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['entity_id', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}
