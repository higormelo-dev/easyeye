<?php

namespace App\Models\Billing;

use App\Enums\Billing\CancellationReason;
use App\Models\{Entity, Subscription};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cancellation extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'gateway_code',
        'reason',
        'source',
        'notes',
        'effective_at',
        'requested_by',
        'metadata',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'reason'       => CancellationReason::class,
            'effective_at' => 'datetime',
            'metadata'     => 'array',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
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
