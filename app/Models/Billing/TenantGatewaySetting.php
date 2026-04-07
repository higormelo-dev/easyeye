<?php

namespace App\Models\Billing;

use App\Models\{Entity, Plan};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantGatewaySetting extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'plan_id',
        'setting_key',
        'default_gateway_id',
        'fallback_gateway_id',
        'allowed_gateways',
        'fallback_enabled',
        'auto_retry_enabled',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'allowed_gateways'   => 'array',
            'fallback_enabled'   => 'boolean',
            'auto_retry_enabled' => 'boolean',
            'metadata'           => 'array',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
            'deleted_at'         => 'datetime',
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

    public function defaultGateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'default_gateway_id');
    }

    public function fallbackGateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'fallback_gateway_id');
    }
}
