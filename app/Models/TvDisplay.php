<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Support\Str;

class TvDisplay extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    // status values
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE  = 'active';
    const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'entity_id',
        'name',
        'status',
        'token',
        'pin',
        'last_seen_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'active'       => 'boolean',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public static function generatePin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
