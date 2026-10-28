<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class TissIdempotencyKey extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_idempotency_keys';

    protected $fillable = [
        'entity_id',
        'scope',
        'key',
        'fingerprint',
        'status',
        'response_snapshot',
        'last_seen_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_snapshot' => 'array',
            'last_seen_at'      => 'datetime',
            'expires_at'        => 'datetime',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'deleted_at'        => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
