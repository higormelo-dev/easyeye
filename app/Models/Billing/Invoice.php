<?php

namespace App\Models\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\{Entity, Plan, Subscription};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Invoice extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'plan_id',
        'gateway_id',
        'gateway_code',
        'reference',
        'external_invoice_id',
        'external_subscription_id',
        'period_start',
        'period_end',
        'due_at',
        'paid_at',
        'amount',
        'currency',
        'status',
        'billing_reason',
        'metadata',
        'raw_gateway_payload',
        'correlation_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'period_start'        => 'date',
            'period_end'          => 'date',
            'due_at'              => 'datetime',
            'paid_at'             => 'datetime',
            'amount'              => 'decimal:2',
            'status'              => InvoiceStatus::class,
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'invoice_id');
    }
}
