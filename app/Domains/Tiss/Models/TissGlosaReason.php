<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};

class TissGlosaReason extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_glosa_reasons';

    protected $fillable = [
        'code',
        'description',
        'active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active'     => 'boolean',
            'metadata'   => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
