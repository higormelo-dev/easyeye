<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissEntityOperatorCredential extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_entity_operator_credentials';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'environment',
        'endpoint_submission',
        'endpoint_status',
        'endpoint_eligibility',
        'endpoint_authorization',
        'username',
        'password_encrypted',
        'token_encrypted',
        'certificate_path',
        'certificate_password_encrypted',
        'private_key_path',
        'ssl_enabled',
        'active',
        'last_success_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ssl_enabled'     => 'boolean',
            'active'          => 'boolean',
            'last_success_at' => 'datetime',
            'metadata'        => 'array',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
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

    public function contracts(): HasMany
    {
        return $this->hasMany(TissEntityOperatorContract::class, 'credential_id');
    }
}
