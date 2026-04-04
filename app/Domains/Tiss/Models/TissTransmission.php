<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\TissTransmissionStatus;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class TissTransmission extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_transmissions';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'batch_id',
        'xml_document_id',
        'protocol_id',
        'idempotency_key',
        'status',
        'queue',
        'attempt',
        'max_attempts',
        'requested_at',
        'responded_at',
        'http_status',
        'response_time_ms',
        'error_code',
        'error_message',
        'response_payload',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status'           => TissTransmissionStatus::class,
            'attempt'          => 'integer',
            'max_attempts'     => 'integer',
            'requested_at'     => 'datetime',
            'responded_at'     => 'datetime',
            'http_status'      => 'integer',
            'response_time_ms' => 'integer',
            'metadata'         => 'array',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
            'deleted_at'       => 'datetime',
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

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(TissProtocol::class, 'protocol_id');
    }
}
