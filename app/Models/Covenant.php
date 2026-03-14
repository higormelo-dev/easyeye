<?php

namespace App\Models;

use App\Concerns\{HasEntityCode, HasUppercaseFields};
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Covenant extends Model
{
    use HasEntityCode;
    use HasFactory;
    use HasUppercaseFields;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix       = 'CV';
    protected string $codePrefixGlobal = 'CVP';

    protected $fillable = ['entity_id', 'code', 'name', 'color', 'table', 'active'];

    protected array $uppercaseFields = ['name', 'color'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }
}
