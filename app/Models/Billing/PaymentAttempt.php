<?php

namespace App\Models\Billing;

use App\Enums\Billing\PaymentAttemptStatus;
use App\Models\{Entity, Subscription};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'invoice_id',
        'payment_id',
        'gateway_id',
        'gateway_code',
        'attempt_number',
        'status',
        'trigger',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
        'http_status',
        'started_at',
        'finished_at',
        'duration_ms',
        'idempotency_key',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number'   => 'integer',
            'status'           => PaymentAttemptStatus::class,
            'request_payload'  => 'array',
            'response_payload' => 'array',
            'http_status'      => 'integer',
            'started_at'       => 'datetime',
            'finished_at'      => 'datetime',
            'duration_ms'      => 'integer',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
            'deleted_at'       => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }
}
