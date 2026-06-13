<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Enums\BillingClaimStatus;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class BillingClaim extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'GUI';

    protected string $codePrefixGlobal = 'GUP';

    protected $fillable = [
        'entity_id',
        'batch_id',
        'schedule_id',
        'patient_id',
        'doctor_id',
        'covenant_id',
        'code',
        'guide_number',
        'status',
        'attendance_date',
        'due_date',
        'amount',
        'glosa_amount',
        'paid_amount',
        'paid_at',
        'is_tiss_exported',
        'tiss_protocol',
        'authorization_code',
        'tuss_code',
        'procedure_description',
        'quantity',
        'unit_price',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $claim): void {
            if (blank($claim->guide_number) && filled($claim->code)) {
                $claim->guide_number = $claim->code;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status'           => BillingClaimStatus::class,
            'attendance_date'  => 'date',
            'due_date'         => 'date',
            'amount'           => 'decimal:2',
            'glosa_amount'     => 'decimal:2',
            'paid_amount'      => 'decimal:2',
            'paid_at'          => 'datetime',
            'is_tiss_exported' => 'boolean',
            'quantity'         => 'integer',
            'unit_price'       => 'decimal:2',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
            'deleted_at'       => 'datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'batch_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(FinancialCashEntry::class, 'billing_claim_id');
    }
}
