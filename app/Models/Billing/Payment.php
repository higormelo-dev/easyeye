<?php

namespace App\Models\Billing;

use App\Enums\Billing\PaymentStatus;
use App\Models\{Entity, Subscription};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Payment extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'invoice_id',
        'subscription_id',
        'gateway_id',
        'gateway_code',
        'external_payment_id',
        'external_invoice_id',
        'status',
        'amount',
        'currency',
        'paid_at',
        'failed_at',
        'payment_method',
        'gateway_fee',
        'net_amount',
        'metadata',
        'raw_gateway_payload',
        'correlation_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status'              => PaymentStatus::class,
            'amount'              => 'decimal:2',
            'gateway_fee'         => 'decimal:2',
            'net_amount'          => 'decimal:2',
            'paid_at'             => 'datetime',
            'failed_at'           => 'datetime',
            'metadata'            => 'array',
            'raw_gateway_payload' => 'array',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime',
            'deleted_at'          => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'payment_id');
    }
}
