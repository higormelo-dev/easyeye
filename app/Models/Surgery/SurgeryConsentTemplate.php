<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Models\SurgeryType;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};

/**
 * Modelo de TCLE / orientações pré-operatórias da clínica. O texto emitido a
 * um paciente é uma CÓPIA (SurgeryDocumentIssue): editar o modelo não muda
 * documento já emitido.
 *
 * `version` e `default_key` só mudam pelo SurgeryTemplateService.
 *
 * @property SurgeryDocumentType $type
 */
class SurgeryConsentTemplate extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    /** Tipos de documento que têm modelo. */
    public const TYPES = [SurgeryDocumentType::Consent, SurgeryDocumentType::PreOpGuidance];

    protected array $auditExclude = ['body'];

    protected $fillable = [
        'entity_id',
        'type',
        'category',
        'name',
        'body',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type'     => SurgeryDocumentType::class,
            'category' => 'integer',
            'version'  => 'integer',
            'active'   => 'boolean',
        ];
    }

    public function categoryLabel(): ?string
    {
        return $this->category === null ? null : (SurgeryType::$categories[$this->category] ?? null);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
