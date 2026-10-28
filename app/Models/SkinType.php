<?php

namespace App\Models;

use App\Concerns\{HasEntityCode, HasUppercaseName};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class SkinType extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUppercaseName;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'ST';

    protected string $codePrefixGlobal = 'STP';

    protected $fillable = ['entity_id', 'code', 'name', 'active'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }
}
