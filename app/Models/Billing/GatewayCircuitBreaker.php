<?php

namespace App\Models\Billing;

use App\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayCircuitBreaker extends Model
{
    use HasUuids;

    protected $fillable = [
        'gateway_code',
        'entity_id',
        'state',
        'failure_count',
        'failure_threshold',
        'last_trigger_type',
        'last_failure_at',
        'open_until',
    ];

    protected function casts(): array
    {
        return [
            'failure_count'     => 'integer',
            'failure_threshold' => 'integer',
            'last_failure_at'   => 'datetime',
            'open_until'        => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function isOpen(): bool
    {
        return $this->state === 'open'
            && $this->open_until !== null
            && $this->open_until->isFuture();
    }
}
