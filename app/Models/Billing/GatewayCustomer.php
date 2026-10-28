<?php

namespace App\Models\Billing;

use App\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cliente do EasyEye no gateway e o que o nosso lado já fez nele (ver a
 * migration billing_gateway_customers). Hoje: notificações do Asaas
 * desligadas (notifications_disabled_at).
 */
class GatewayCustomer extends Model
{
    use HasUuids;

    protected $table = 'billing_gateway_customers';

    protected $fillable = [
        'gateway_code',
        'external_customer_id',
        'entity_id',
        'notifications_disabled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'notifications_disabled_at' => 'datetime',
            'metadata'                  => 'array',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    /** As notificações do gateway já foram desligadas para este cliente. */
    public static function notificationsDisabled(string $gatewayCode, string $externalCustomerId): bool
    {
        return static::query()
            ->where('gateway_code', $gatewayCode)
            ->where('external_customer_id', $externalCustomerId)
            ->whereNotNull('notifications_disabled_at')
            ->exists();
    }

    /** Marca (idempotente) que as notificações foram desligadas. */
    public static function markNotificationsDisabled(string $gatewayCode, string $externalCustomerId, ?string $entityId = null, array $metadata = []): self
    {
        $row = static::query()->firstOrNew([
            'gateway_code'         => $gatewayCode,
            'external_customer_id' => $externalCustomerId,
        ]);

        $row->entity_id ??= $entityId;
        $row->notifications_disabled_at ??= now();
        $row->metadata = [...(array) ($row->metadata ?? []), ...$metadata];
        $row->save();

        return $row;
    }
}
