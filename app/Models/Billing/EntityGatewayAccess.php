<?php

namespace App\Models\Billing;

use App\Models\Entity;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permissão de acesso a gateway de pagamento (tenant context) por clínica.
 *
 * Uma clínica só pode configurar e usar um gateway no seu painel
 * quando existe um registro aqui com enabled = true.
 * Gerenciado pelo SaaS owner em /panel/manager/gateways.
 */
class EntityGatewayAccess extends Model
{
    use HasAuditColumns;
    use HasUuids;

    protected $table = 'entity_gateway_access';

    protected $fillable = [
        'entity_id',
        'gateway_id',
        'enabled',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled'    => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }
}
