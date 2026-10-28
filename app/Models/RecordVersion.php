<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class RecordVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'user_id',
        'versionable_type',
        'versionable_id',
        'version',
        'data',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
