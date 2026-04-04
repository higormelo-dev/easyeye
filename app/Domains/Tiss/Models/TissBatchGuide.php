<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class TissBatchGuide extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_batch_guides';

    protected $fillable = [
        'entity_id',
        'batch_id',
        'guide_id',
        'attached_at',
    ];

    protected function casts(): array
    {
        return [
            'attached_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
            'deleted_at'  => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TissBatch::class, 'batch_id');
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TissGuide::class, 'guide_id');
    }
}
