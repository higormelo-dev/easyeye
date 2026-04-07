<?php

namespace App\Models\Billing;

use App\Enums\Billing\CredentialScope;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayCredential extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'gateway_id',
        'entity_id',
        'scope',
        'label',
        'credentials',
        'webhook_secret',
        'active',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'scope'          => CredentialScope::class,
            'credentials'    => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'active'         => 'boolean',
            'valid_from'     => 'datetime',
            'valid_to'       => 'datetime',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
