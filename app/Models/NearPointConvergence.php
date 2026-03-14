<?php

namespace App\Models;

use App\Concerns\{HasEntityCode, HasUppercaseName};
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class NearPointConvergence extends Model
{
    use HasEntityCode;
    use HasUppercaseName;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix       = 'NPC';
    protected string $codePrefixGlobal = 'NPCP';

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
