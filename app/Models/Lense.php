<?php

namespace App\Models;

use App\Concerns\{HasEntityCode, HasUppercaseName};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class Lense extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUppercaseName;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'LS';

    protected string $codePrefixGlobal = 'LSP';

    protected $fillable = ['entity_id', 'code', 'name', 'away', 'near', 'active'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }
}
