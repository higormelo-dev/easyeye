<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\TissAppealStatus;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class TissGlosaAppeal extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_glosa_appeals';

    protected $fillable = [
        'entity_id',
        'glosa_id',
        'protocol_id',
        'xml_document_id',
        'appeal_number',
        'status',
        'reason',
        'requested_amount',
        'accepted_amount',
        'submitted_at',
        'resolved_at',
        'result_notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status'           => TissAppealStatus::class,
            'requested_amount' => 'decimal:2',
            'accepted_amount'  => 'decimal:2',
            'submitted_at'     => 'datetime',
            'resolved_at'      => 'datetime',
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

    public function glosa(): BelongsTo
    {
        return $this->belongsTo(TissGlosa::class, 'glosa_id');
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(TissProtocol::class, 'protocol_id');
    }

    public function xmlDocument(): BelongsTo
    {
        return $this->belongsTo(TissXmlDocument::class, 'xml_document_id');
    }
}
