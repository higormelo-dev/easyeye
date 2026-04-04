<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\HasMany, SoftDeletes};

class TissVersion extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_versions';

    protected $fillable = [
        'code',
        'layout_version',
        'effective_from',
        'effective_until',
        'active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_from'  => 'date',
            'effective_until' => 'date',
            'active'          => 'boolean',
            'metadata'        => 'array',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(TissEntityOperatorContract::class, 'preferred_tiss_version_id');
    }

    public function guides(): HasMany
    {
        return $this->hasMany(TissGuide::class, 'version_id');
    }
}
