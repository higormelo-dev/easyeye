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
    use Auditable;
    use HasAuditColumns;
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
        'is_featured',
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
        return self::periodLabel($this->billing_cycle);
    }

    /** Sufixo do preço no idioma do usuário ("/mês", "/ano"...). */
    public static function periodLabel(BillingCycle $cycle): string
    {
        return __("subscriptions.billing_period.{$cycle->value}");
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class, 'plan_id');
    }

    /**
     * Preço de cada ciclo oferecido, na ordem do mais curto ao mais longo
     * (['monthly' => 299.9, 'yearly' => 2878.99]).
     *
     * Plano sem preços por ciclo (criado antes deles ou por factory) oferece
     * só o ciclo/preço de referência — mesmo comportamento de antes.
     *
     * @return array<string, float>
     */
    public function cyclePrices(): array
    {
        $byCycle = $this->prices
            ->mapWithKeys(fn (PlanPrice $p) => [$p->billing_cycle->value => (float) $p->price])
            ->all();

        if ($byCycle === [] && in_array($this->billing_cycle, PlanPrice::SELLABLE_CYCLES, true)) {
            $byCycle = [$this->billing_cycle->value => (float) $this->price];
        }

        $ordered = [];

        foreach (PlanPrice::SELLABLE_CYCLES as $cycle) {
            if (array_key_exists($cycle->value, $byCycle)) {
                $ordered[$cycle->value] = $byCycle[$cycle->value];
            }
        }

        return $ordered;
    }

    public function priceFor(BillingCycle $cycle): ?float
    {
        return $this->cyclePrices()[$cycle->value] ?? null;
    }

    public function offersCycle(BillingCycle $cycle): bool
    {
        return $this->priceFor($cycle) !== null;
    }

    /**
     * Tem ao menos um ciclo à venda. Plano antigo só vitalício não tem —
     * fica fora do site e do cadastro até o admin cadastrar um ciclo.
     */
    public function isSellable(): bool
    {
        return $this->cyclePrices() !== [];
    }

    /**
     * Ciclo usado quando ninguém escolheu: o de referência do plano, ou o
     * primeiro oferecido.
     */
    public function defaultCycle(): ?BillingCycle
    {
        if ($this->offersCycle($this->billing_cycle)) {
            return $this->billing_cycle;
        }

        $first = array_key_first($this->cyclePrices());

        return $first ? BillingCycle::from($first) : null;
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
