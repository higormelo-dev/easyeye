<?php

namespace App\Models\Billing;

use App\Enums\Billing\BillingEventType;
use App\Models\{Entity, Subscription};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialEvent extends Model
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
        'event_type',
        'amount',
        'currency',
        'effective_at',
        'metadata',
        'correlation_id',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'event_type'   => BillingEventType::class,
            'amount'       => 'decimal:2',
            'effective_at' => 'datetime',
            'metadata'     => 'array',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
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
}
