<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\{ReferralEventType, ReferralRewardType};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ReferralCode extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'code',
        'reward_type',
        'reward_value',
        'uses_count',
        'max_uses',
        'active',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_type'  => ReferralRewardType::class,
            'reward_value' => 'integer',
            'uses_count'   => 'integer',
            'max_uses'     => 'integer',
            'active'       => 'boolean',
            'expires_at'   => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReferralEvent::class, 'referral_code_id');
    }

    /** Clínicas que usaram este código no cadastro */
    public function referredEntities(): HasMany
    {
        return $this->hasMany(Entity::class, 'referral_code_id');
    }

    public function isUsable(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return ! ($this->max_uses !== null && $this->uses_count >= $this->max_uses);
    }

    /** Contagem de conversões (trial → pago) geradas por este código */
    public function conversionsCount(): int
    {
        return $this->events()
            ->where('event_type', ReferralEventType::PlanActivated->value)
            ->count();
    }
}
