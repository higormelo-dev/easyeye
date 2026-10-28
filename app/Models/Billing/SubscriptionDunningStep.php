<?php

namespace App\Models\Billing;

use App\Enums\Billing\DunningStep;
use App\Models\{Entity, Subscription};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etapa da régua de cobrança já cumprida para uma assinatura e um vencimento
 * (ver App\Services\Billing\DunningService). Única por
 * (subscription_id, step, due_on).
 */
class SubscriptionDunningStep extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'invoice_id',
        'step',
        'due_on',
        'recipients_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'step'             => DunningStep::class,
            'due_on'           => 'date',
            'recipients_count' => 'integer',
            'metadata'         => 'array',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
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
}
