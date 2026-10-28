<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\HasMany, SoftDeletes};

class TissOperator extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_operators';

    protected $fillable = [
        'ans_code',
        'name',
        'trade_name',
        'tax_id',
        'webservice_base_url',
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

    public function credentials(): HasMany
    {
        return $this->hasMany(TissEntityOperatorCredential::class, 'operator_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(TissEntityOperatorContract::class, 'operator_id');
    }
}
