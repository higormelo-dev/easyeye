<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissEntityOperatorContract extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_entity_operator_contracts';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'credential_id',
        'preferred_tiss_version_id',
        'contract_code',
        'plan_code',
        'billing_regime',
        'requires_authorization',
        'default_guide_type',
        'active',
        'rules',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requires_authorization' => 'boolean',
            'active'                 => 'boolean',
            'rules'                  => 'array',
            'metadata'               => 'array',
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime',
            'deleted_at'             => 'datetime',
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

    public function credential(): BelongsTo
    {
        return $this->belongsTo(TissEntityOperatorCredential::class, 'credential_id');
    }

    public function preferredVersion(): BelongsTo
    {
        return $this->belongsTo(TissVersion::class, 'preferred_tiss_version_id');
    }

    public function guides(): HasMany
    {
        return $this->hasMany(TissGuide::class, 'contract_id');
    }
}
