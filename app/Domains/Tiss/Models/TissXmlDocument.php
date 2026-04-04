<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\{TissXmlDirection, TissXmlStatus};
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissXmlDocument extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_xml_documents';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'batch_id',
        'guide_id',
        'version_id',
        'document_type',
        'direction',
        'status',
        'file_path',
        'file_hash',
        'xml_content',
        'generated_at',
        'validated_at',
        'signed_at',
        'signature_algorithm',
        'validation_errors',
        'generated_by_job_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'direction'         => TissXmlDirection::class,
            'status'            => TissXmlStatus::class,
            'generated_at'      => 'datetime',
            'validated_at'      => 'datetime',
            'signed_at'         => 'datetime',
            'validation_errors' => 'array',
            'metadata'          => 'array',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'deleted_at'        => 'datetime',
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

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TissGuide::class, 'guide_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TissVersion::class, 'version_id');
    }

    public function protocols(): HasMany
    {
        return $this->hasMany(TissProtocol::class, 'xml_document_id');
    }

    public function transmissions(): HasMany
    {
        return $this->hasMany(TissTransmission::class, 'xml_document_id');
    }
}
