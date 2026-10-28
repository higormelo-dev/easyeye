<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class TissGuideLink extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_guide_links';

    protected $fillable = [
        'entity_id',
        'parent_guide_id',
        'child_guide_id',
        'link_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function parentGuide(): BelongsTo
    {
        return $this->belongsTo(TissGuide::class, 'parent_guide_id');
    }

    public function childGuide(): BelongsTo
    {
        return $this->belongsTo(TissGuide::class, 'child_guide_id');
    }
}
