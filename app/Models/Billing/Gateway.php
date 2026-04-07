<?php

namespace App\Models\Billing;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gateway extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'active',
        'supports_subscriptions',
        'supports_one_time_charges',
        'supports_refunds',
        'supports_webhooks',
        'priority',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'active'                    => 'boolean',
            'supports_subscriptions'    => 'boolean',
            'supports_one_time_charges' => 'boolean',
            'supports_refunds'          => 'boolean',
            'supports_webhooks'         => 'boolean',
            'priority'                  => 'integer',
            'config'                    => 'array',
            'created_at'                => 'datetime',
            'updated_at'                => 'datetime',
            'deleted_at'                => 'datetime',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(GatewayCredential::class, 'gateway_id');
    }
}
