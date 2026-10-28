<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\{AnesthesiaType, SurgeryDescriptionStatus};
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Models\{Doctor, Patient};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use LogicException;

/**
 * Descrição cirúrgica: rascunho editável → FINALIZADA (hash do conteúdo + retrato
 * do cabeçalho). Depois de finalizada a linha não muda mais: correção é ADENDO
 * (SurgicalDescriptionAddendum), nunca edição. Nunca é apagada.
 *
 * @property SurgeryDescriptionStatus $status
 */
class SurgicalDescription extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasAuditColumns;
    use HasUuids;

    /** Trilha de auditoria sem o texto clínico (ele já está na própria linha). */
    protected array $auditExclude = ['findings', 'technique_performed', 'complications', 'header_snapshot'];

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'patient_id',
        'surgeon_doctor_id',
        'performed_start_at',
        'performed_end_at',
        'anesthesia_used',
        'findings',
        'technique_performed',
        'no_complications',
        'complications',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $description): void {
            if ($description->getRawOriginal('status') === SurgeryDescriptionStatus::Finalized->value) {
                throw new LogicException('Descrição cirúrgica finalizada é imutável: registre um adendo.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Descrição cirúrgica não pode ser apagada.');
        });
    }

    protected function casts(): array
    {
        return [
            'status'             => SurgeryDescriptionStatus::class,
            'anesthesia_used'    => AnesthesiaType::class,
            'performed_start_at' => 'datetime',
            'performed_end_at'   => 'datetime',
            'finalized_at'       => 'datetime',
            'no_complications'   => 'boolean',
            'header_snapshot'    => 'array',
            'revision'           => 'integer',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'surgeon_doctor_id');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(SurgicalDescriptionAddendum::class)->orderBy('created_at');
    }

    public function isFinalized(): bool
    {
        return $this->status === SurgeryDescriptionStatus::Finalized;
    }

    /** Conteúdo que entra no hash da finalização. @return array<string, mixed> */
    public function hashableContent(): array
    {
        return [
            'case'                => (string) $this->surgical_case_id,
            'patient'             => (string) $this->patient_id,
            'surgeon'             => (string) $this->surgeon_doctor_id,
            'performed_start_at'  => $this->performed_start_at?->toIso8601String(),
            'performed_end_at'    => $this->performed_end_at?->toIso8601String(),
            'anesthesia_used'     => $this->anesthesia_used?->value,
            'findings'            => $this->findings,
            'technique_performed' => $this->technique_performed,
            'no_complications'    => (bool) $this->no_complications,
            'complications'       => $this->complications,
            'header'              => $this->header_snapshot,
        ];
    }
}
