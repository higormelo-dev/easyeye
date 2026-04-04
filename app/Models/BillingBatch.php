<?php

declare(strict_types = 1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Enums\BillingBatchStatus;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class BillingBatch extends Model
{
    use HasAuditColumns;
    use Auditable;
    use HasEntityCode;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'LOT';

    protected string $codePrefixGlobal = 'LOP';

    protected $fillable = [
        'entity_id',
        'covenant_id',
        'code',
        'status',
        'tiss_version',
        'tiss_layout_version',
        'period_start',
        'period_end',
        'due_date',
        'issued_at',
        'submitted_at',
        'processed_at',
        'total_claims',
        'total_amount',
        'xml_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingBatchStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'total_claims' => 'integer',
            'total_amount' => 'decimal:2',
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

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(BillingClaim::class, 'batch_id');
    }
}

