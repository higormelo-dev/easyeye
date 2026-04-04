<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissGlosa extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_glosas';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'guide_id',
        'guide_item_id',
        'return_id',
        'protocol_id',
        'status',
        'glosa_code',
        'glosa_description',
        'amount',
        'identified_at',
        'resolved_at',
        'resolution_notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status'        => TissGlosaStatus::class,
            'amount'        => 'decimal:2',
            'identified_at' => 'date',
            'resolved_at'   => 'date',
            'metadata'      => 'array',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
            'deleted_at'    => 'datetime',
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

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TissGuide::class, 'guide_id');
    }

    public function guideItem(): BelongsTo
    {
        return $this->belongsTo(TissGuideItem::class, 'guide_item_id');
    }

    public function returnDocument(): BelongsTo
    {
        return $this->belongsTo(TissReturn::class, 'return_id');
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(TissProtocol::class, 'protocol_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(TissGlosaAppeal::class, 'glosa_id');
    }
}
