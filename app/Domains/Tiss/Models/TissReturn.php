<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\TissReturnStatus;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissReturn extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_returns';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'version_id',
        'protocol_id',
        'xml_document_id',
        'return_type',
        'status',
        'received_at',
        'processed_at',
        'summary',
        'raw_payload',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status'       => TissReturnStatus::class,
            'received_at'  => 'datetime',
            'processed_at' => 'datetime',
            'summary'      => 'array',
            'metadata'     => 'array',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
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

    public function version(): BelongsTo
    {
        return $this->belongsTo(TissVersion::class, 'version_id');
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(TissProtocol::class, 'protocol_id');
    }

    public function xmlDocument(): BelongsTo
    {
        return $this->belongsTo(TissXmlDocument::class, 'xml_document_id');
    }

    public function glosas(): HasMany
    {
        return $this->hasMany(TissGlosa::class, 'return_id');
    }
}
