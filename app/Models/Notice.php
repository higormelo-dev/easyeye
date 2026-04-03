<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\BelongsToMany};

class Notice extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'author_id',
        'content',
        'priority',
        'pinned',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'priority'   => 'integer',
            'pinned'     => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'author_id');
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(EntityUser::class, 'notice_reads', 'notice_id', 'entity_user_id')
            ->withPivot('read_at');
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function isUrgent(): bool
    {
        return $this->priority === 1;
    }
}
