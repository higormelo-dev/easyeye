<?php

namespace App\Models;

use App\Enums\FeatureKey;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureUsage extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'feature',
        'period',
        'used',
        'limit_snapshot',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'feature'        => FeatureKey::class,
            'used'           => 'integer',
            'limit_snapshot' => 'integer',
            'last_used_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
