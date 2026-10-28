<?php

namespace App\Models\Billing;

use App\Enums\Billing\WebhookEventStatus;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'gateway_id',
        'entity_id',
        'gateway_code',
        'external_event_id',
        'event_type',
        'event_hash',
        'signature',
        'headers',
        'payload',
        'normalized_payload',
        'status',
        'attempts',
        'last_error',
        'received_at',
        'processed_at',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'headers'            => 'array',
            'payload'            => 'array',
            'normalized_payload' => 'array',
            'status'             => WebhookEventStatus::class,
            'attempts'           => 'integer',
            'received_at'        => 'datetime',
            'processed_at'       => 'datetime',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
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
