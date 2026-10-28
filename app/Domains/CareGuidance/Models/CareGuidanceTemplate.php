<?php

declare(strict_types=1);

namespace App\Domains\CareGuidance\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Models\Procedure;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Modelo de orientação pós-procedimento da clínica (Fase 6 / S14): título, texto, local (rótulo livre), esquema de
 * colírios e PDFs anexos. Vale para um procedimento do catálogo ou para qualquer um (`procedure_id` nulo). É o MODELO:
 * o que o paciente recebe é o retrato congelado em CareGuidanceIssue.
 *
 * @property string                          $id
 * @property string                          $entity_id
 * @property ?string                         $procedure_id
 * @property string                          $title
 * @property ?string                         $location_label
 * @property string                          $body
 * @property list<array<string, mixed>>|null $eye_drops
 * @property bool                            $active
 */
class CareGuidanceTemplate extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'care_guidance_templates';

    protected $fillable = ['entity_id', 'procedure_id', 'title', 'location_label', 'body', 'eye_drops', 'active'];

    protected function casts(): array
    {
        return [
            'eye_drops' => 'array',
            'active'    => 'boolean',
        ];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CareGuidanceAttachment::class, 'template_id')->orderBy('created_at');
    }
}
