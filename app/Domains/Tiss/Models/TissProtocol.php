<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\TissProtocolStatus;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissProtocol extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_protocols';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'batch_id',
        'xml_document_id',
        'protocol_number',
        'status',
        'acknowledged_at',
        'processed_at',
        'closed_at',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'status'          => TissProtocolStatus::class,
            'acknowledged_at' => 'datetime',
            'processed_at'    => 'datetime',
            'closed_at'       => 'datetime',
            'details'         => 'array',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TissBatch::class, 'batch_id');
    }

    public function xmlDocument(): BelongsTo
    {
        return $this->belongsTo(TissXmlDocument::class, 'xml_document_id');
    }

    public function transmissions(): HasMany
    {
        return $this->hasMany(TissTransmission::class, 'protocol_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(TissReturn::class, 'protocol_id');
    }
}
