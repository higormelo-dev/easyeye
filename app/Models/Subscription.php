<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Subscription extends Model
{
    use HasAuditColumns;
    use Auditable;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'grace_period_ends_at',
        'cancelled_at',
        'gateway',
        'gateway_subscription_id',
        'gateway_payload',
    ];

    protected function casts(): array
    {
        return [
            'status'               => SubscriptionStatus::class,
            'trial_ends_at'        => 'datetime',
            'starts_at'            => 'datetime',
            'ends_at'              => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'cancelled_at'         => 'datetime',
            'gateway_payload'      => 'array',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
            'deleted_at'           => 'datetime',
        ];
    }

    // ── Relacionamentos ─────────────────────────────────────────────────────

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id')->withTrashed();
    }

    public function featureUsages(): HasMany
    {
        return $this->hasMany(FeatureUsage::class, 'subscription_id');
    }

    // ── Estado da assinatura ─────────────────────────────────────────────────

    /**
     * Assinatura está ativa (trial válido ou plano pago vigente).
     */
    public function isActive(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Trial  => $this->trial_ends_at?->isFuture() ?? false,
            SubscriptionStatus::Active => is_null($this->ends_at) || $this->ends_at->isFuture(),
            default                    => false,
        };
    }

    /**
     * Assinatura está no período de trial.
     */
    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trial
            && ($this->trial_ends_at?->isFuture() ?? false);
    }

    /**
     * Período de graça após expiração (acesso mantido temporariamente).
     */
    public function inGracePeriod(): bool
    {
        return !$this->isActive()
            && ($this->grace_period_ends_at?->isFuture() ?? false);
    }

    /**
     * Empresa tem acesso (ativa ou em período de graça).
     */
    public function hasAccess(): bool
    {
        return $this->isActive() || $this->inGracePeriod();
    }

    // ── Escopos ─────────────────────────────────────────────────────────────

    public function scopeForEntity($query, string $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeAccessible($query)
    {
        return $query->whereIn('status', [
            SubscriptionStatus::Trial->value,
            SubscriptionStatus::Active->value,
        ])->where(function ($q) {
            $q->whereNull('ends_at')
              ->orWhere('ends_at', '>', now())
              ->orWhere('grace_period_ends_at', '>', now());
        });
    }
}
