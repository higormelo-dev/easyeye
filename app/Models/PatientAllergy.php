<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClinicalSafety\{AllergyCategory, AllergySeverity, AllergyStatus};
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alergia do paciente (Fase 2 / A1) — nível PACIENTE, não consulta.
 *
 * Quem registrou e quando = created_by/created_at (HasAuditColumns). A
 * recepção só registra como "informada pelo paciente"; o médico com cadastro
 * ativo confirma (confirmed_*) ou descarta (discarded_* + motivo). Descartada
 * nunca é apagada: fica no histórico e na exportação LGPD. Toda alteração vai
 * para audit_logs (Auditable) — dado de saúde sensível (LGPD art. 11).
 *
 * @property AllergyCategory      $category
 * @property AllergyStatus        $status
 * @property AllergySeverity|null $severity
 */
class PatientAllergy extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'patient_id',
        'category',
        'substance',
        'medicine_id',
        'active_ingredient',
        'reaction',
        'severity',
        'status',
        'confirmed_at',
        'confirmed_by',
        'discarded_at',
        'discarded_by',
        'discard_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'category'     => AllergyCategory::class,
            'severity'     => AllergySeverity::class,
            'status'       => AllergyStatus::class,
            'confirmed_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AllergyStatus::activeValues());
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function discardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }
}
