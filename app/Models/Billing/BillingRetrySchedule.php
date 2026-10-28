<?php

namespace App\Models\Billing;

use App\Models\{Entity, Subscription};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingRetrySchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'invoice_id',
        'gateway_code',
        'attempt_number',
        'scheduled_for',
        'executed_at',
        'status',
        'result_message',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'executed_at'   => 'datetime',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeDue($query)
    {
        return $query->where('status', 'pending')
            ->where('scheduled_for', '<=', now());
    }
}
