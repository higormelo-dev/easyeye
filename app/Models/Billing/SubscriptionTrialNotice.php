<?php

namespace App\Models\Billing;

use App\Models\{Entity, Subscription};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso de fim do teste grátis já enviado para uma assinatura, passo e data
 * de fim do trial (ver App\Services\Billing\TrialEndingNoticeService). Único
 * por (subscription_id, step, trial_ends_on).
 */
class SubscriptionTrialNotice extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'step',
        'trial_ends_on',
        'recipients_count',
        'whatsapp_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_on'    => 'date',
            'recipients_count' => 'integer',
            'whatsapp_count'   => 'integer',
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
}
