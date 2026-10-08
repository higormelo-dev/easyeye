<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClinicalSafety\{MedicationSource, MedicationStatus};
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medicação em uso do paciente (Fase 2 / A2) — lista persistente entre
 * consultas. Catálogo CMED opcional (medicine_id) ou nome livre; o princípio
 * ativo alimenta os alertas de risco ocular. Mesma regra das alergias: a
 * recepção registra como informada (sem confirmed_*); o médico confirma,
 * suspende ou descarta. Suspensa/descartada ficam no histórico.
 *
 * @property MedicationStatus $status
 * @property MedicationSource $source
 */
class PatientMedication extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'patient_id',
        'medicine_id',
        'name',
        'active_ingredient',
        'posology',
        'started_on',
        'ended_on',
        'status',
        'source',
        'confirmed_at',
        'confirmed_by',
        'discarded_at',
        'discarded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'       => MedicationStatus::class,
            'source'       => MedicationSource::class,
            'started_on'   => 'date',
            'ended_on'     => 'date',
            'confirmed_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MedicationStatus::Active->value);
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
}
