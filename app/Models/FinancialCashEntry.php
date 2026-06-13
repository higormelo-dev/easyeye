<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PaymentMethodCast;
use App\Concerns\HasEntityCode;
use App\Enums\{CashEntryNature, FinancialEntryStatus, FinancialEntryType};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\MorphTo, SoftDeletes};

class FinancialCashEntry extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'FLC';

    protected string $codePrefixGlobal = 'FLP';

    protected $fillable = [
        'entity_id',
        'category_id',
        'covenant_id',
        'billing_claim_id',
        'patient_id',
        'doctor_id',
        'procedure_id',
        'code',
        'entry_date',
        'description',
        'type',
        'status',
        'amount',
        'amount_cash',
        'amount_credit',
        'amount_debit',
        'installments',
        'payment_method',
        'nature',
        'reference_type',
        'reference_id',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'entry_date'     => 'date',
            'type'           => FinancialEntryType::class,
            'status'         => FinancialEntryStatus::class,
            'payment_method' => PaymentMethodCast::class,
            'nature'         => CashEntryNature::class,
            'amount'         => 'decimal:2',
            'amount_cash'    => 'decimal:2',
            'amount_credit'  => 'decimal:2',
            'amount_debit'   => 'decimal:2',
            'installments'   => 'integer',
            'active'         => 'boolean',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $query = static::where($field ?? $this->getRouteKeyName(), $value);

        if ($entityId = session('selected_entity_id')) {
            $query->where('entity_id', $entityId);
        }

        return $query->firstOrFail();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function billingClaim(): BelongsTo
    {
        return $this->belongsTo(BillingClaim::class, 'billing_claim_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class, 'procedure_id');
    }

    public function referenceable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }
}
