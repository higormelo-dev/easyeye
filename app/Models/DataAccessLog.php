<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\DataAccessPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

/**
 * Registra toda leitura/visualização de dados sensíveis.
 * CFM Res. 2.227/2018 + LGPD Art. 37.
 */
class DataAccessLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'user_id',
        'resource_type',
        'resource_id',
        'patient_id',
        'purpose',
        'justification',
        'ip_address',
        'user_agent',
        'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose'     => DataAccessPurpose::class,
            'accessed_at' => 'datetime',
        ];
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
