<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preço de um ciclo de cobrança oferecido pelo plano (ex.: Pro anual).
 */
class PlanPrice extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;

    /**
     * Ciclos que o plano pode vender. Vitalício não faz parte da estratégia:
     * o caso continua no enum só para ler dados antigos.
     */
    public const SELLABLE_CYCLES = [
        BillingCycle::Monthly,
        BillingCycle::Quarterly,
        BillingCycle::Semiannual,
        BillingCycle::Yearly,
    ];

    protected $fillable = [
        'plan_id',
        'billing_cycle',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'price'         => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** @return list<string> */
    public static function sellableCycleValues(): array
    {
        return array_map(static fn (BillingCycle $c) => $c->value, self::SELLABLE_CYCLES);
    }
}
