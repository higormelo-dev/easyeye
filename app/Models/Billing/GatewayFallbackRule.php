<?php

namespace App\Models\Billing;

use App\Enums\Billing\FallbackTriggerType;
use App\Models\{Entity, Plan};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayFallbackRule extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'plan_id',
        'primary_gateway_code',
        'fallback_gateway_code',
        'trigger_type',
        'active',
        'priority',
        'max_retries',
        'cooldown_seconds',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type'     => FallbackTriggerType::class,
            'active'           => 'boolean',
            'priority'         => 'integer',
            'max_retries'      => 'integer',
            'cooldown_seconds' => 'integer',
            'metadata'         => 'array',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
            'deleted_at'       => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
