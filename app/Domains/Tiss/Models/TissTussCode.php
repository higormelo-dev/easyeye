<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};

class TissTussCode extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_tuss_codes';

    protected $fillable = [
        'code',
        'table_code',
        'description',
        'effective_from',
        'effective_until',
        'active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_from'  => 'date',
            'effective_until' => 'date',
            'active'          => 'boolean',
            'metadata'        => 'array',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }
}
