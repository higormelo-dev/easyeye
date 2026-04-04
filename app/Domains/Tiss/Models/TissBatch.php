<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\BelongsToMany, Relations\HasMany, SoftDeletes};

class TissBatch extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_batches';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'contract_id',
        'version_id',
        'xml_document_id',
        'batch_number',
        'reference_month',
        'status',
        'guides_count',
        'total_amount',
        'closed_at',
        'submitted_at',
        'processed_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status'       => TissBatchStatus::class,
            'guides_count' => 'integer',
            'total_amount' => 'decimal:2',
            'closed_at'    => 'datetime',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata'     => 'array',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(TissOperator::class, 'operator_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(TissEntityOperatorContract::class, 'contract_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TissVersion::class, 'version_id');
    }

    public function xmlDocument(): BelongsTo
    {
        return $this->belongsTo(TissXmlDocument::class, 'xml_document_id');
    }

    public function guides(): BelongsToMany
    {
        return $this->belongsToMany(TissGuide::class, 'tiss_batch_guides', 'batch_id', 'guide_id')
            ->withPivot(['id', 'entity_id', 'attached_at'])
            ->withTimestamps();
    }

    public function guideLinks(): HasMany
    {
        return $this->hasMany(TissBatchGuide::class, 'batch_id');
    }

    public function protocols(): HasMany
    {
        return $this->hasMany(TissProtocol::class, 'batch_id');
    }

    public function transmissions(): HasMany
    {
        return $this->hasMany(TissTransmission::class, 'batch_id');
    }
}
