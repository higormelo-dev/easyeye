<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Billing\{Cancellation, Invoice, SubscriptionChange};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Subscription extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'plan_id',
        'status',
        'billing_state',
        'last_billing_error',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'last_payment_at',
        'grace_period_ends_at',
        'cancelled_at',
        'cancelled_reason',
        'renewed_at',
        'gateway',
        'pinned_gateway',
        'gateway_customer_id',
        'gateway_subscription_id',
        'idempotency_key',
        'correlation_id',
        'current_invoice_id',
        'gateway_payload',
        'gateway_migration_locked_until',
    ];

    protected function casts(): array
    {
        return [
            'status'                         => SubscriptionStatus::class,
            'billing_state'                  => 'string',
            'last_billing_error'             => 'string',
            'trial_ends_at'                  => 'datetime',
            'starts_at'                      => 'datetime',
            'ends_at'                        => 'datetime',
            'last_payment_at'                => 'datetime',
            'grace_period_ends_at'           => 'datetime',
            'cancelled_at'                   => 'datetime',
            'renewed_at'                     => 'datetime',
            'gateway'                        => 'string',
            'pinned_gateway'                 => 'string',
            'gateway_customer_id'            => 'string',
            'idempotency_key'                => 'string',
            'gateway_payload'                => 'array',
            'gateway_migration_locked_until' => 'datetime',
            'created_at'                     => 'datetime',
            'updated_at'                     => 'datetime',
            'deleted_at'                     => 'datetime',
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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class, 'subscription_id');
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(Cancellation::class, 'subscription_id');
    }

    public function currentInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'current_invoice_id');
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
        return ! $this->isActive()
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
        return $query->where(function ($q) {
            $q->where(function ($subQ) {
                $subQ->where('status', SubscriptionStatus::Trial->value)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', now());
            })->orWhere(function ($subQ) {
                $subQ->where('status', SubscriptionStatus::Active->value)
                    ->where(function ($dateQ) {
                        $dateQ->whereNull('ends_at')
                            ->orWhere('ends_at', '>', now());
                    });
            })->orWhere(function ($subQ) {
                $subQ->whereIn('status', [
                    SubscriptionStatus::PastDue->value,
                    SubscriptionStatus::Expired->value,
                    SubscriptionStatus::Cancelled->value,
                ])->whereNotNull('grace_period_ends_at')
                    ->where('grace_period_ends_at', '>', now());
            });
        });
    }
}
