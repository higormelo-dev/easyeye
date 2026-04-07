<?php

namespace App\Models\Billing;

use App\Models\{Entity, Subscription};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'invoice_id',
        'payment_id',
        'payment_attempt_id',
        'webhook_event_id',
        'gateway_code',
        'level',
        'message',
        'context',
        'correlation_id',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context'    => 'array',
            'created_at' => 'datetime',
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

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }
}
