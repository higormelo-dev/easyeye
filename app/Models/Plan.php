<?php

namespace App\Models;

use App\Enums\{BillingCycle, FeatureKey};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasAuditColumns;
    use Auditable;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'billing_cycle',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price'         => 'decimal:2',
            'active'        => 'boolean',
            'is_featured'   => 'boolean',
            'billing_cycle' => BillingCycle::class,
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
            'deleted_at'    => 'datetime',
        ];
    }

    public function pricePeriodLabel(): string
    {
        return match ($this->billing_cycle) {
            BillingCycle::Monthly    => '/mês',
            BillingCycle::Quarterly  => '/trimestre',
            BillingCycle::Semiannual => '/semestre',
            BillingCycle::Yearly     => '/ano',
            BillingCycle::Lifetime   => '/vitalício',
        };
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    /**
     * Retorna o valor de uma feature específica deste plano, ou null se não definida.
     */
    public function featureValue(FeatureKey $feature): ?string
    {
        return $this->features
            ->where('feature', $feature->value)
            ->first()
            ?->value;
    }

    /**
     * Escopo para planos ativos.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
