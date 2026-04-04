<?php

declare(strict_types = 1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Enums\{FinancialEntryStatus, FinancialEntryType};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class FinancialCashEntry extends Model
{
    use HasAuditColumns;
    use Auditable;
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
        'code',
        'entry_date',
        'description',
        'type',
        'status',
        'amount',
        'payment_method',
        'reference_type',
        'reference_id',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'type' => FinancialEntryType::class,
            'status' => FinancialEntryStatus::class,
            'amount' => 'decimal:2',
            'active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
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
}

