<?php

namespace App\Models\Billing;

use App\Models\{Entity, Plan, Subscription};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionChange extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'previous_plan_id',
        'new_plan_id',
        'previous_gateway_code',
        'new_gateway_code',
        'change_type',
        'reason',
        'changed_by',
        'effective_at',
        'metadata',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
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

    public function previousPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'previous_plan_id');
    }

    public function newPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'new_plan_id');
    }
}
