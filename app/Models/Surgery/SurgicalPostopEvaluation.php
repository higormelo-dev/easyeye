<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\{PostOpAdherence, PostOpEvaluationStatus, PostOpFinding, PostOpMilestone, SurgeryEye};
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Models\{Doctor, Patient, Schedule, VisualAcuityType};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use LogicException;

/**
 * Avaliação pós-operatória estruturada, por marco e olho (ADR 0003, D12).
 *
 * Rascunho é editável; FINALIZADA é imutável (hash do conteúdo clínico) e
 * nunca apagada — correção é adendo (`SurgicalPostopEvaluationAddendum`),
 * que pode trazer a refração corrigida para os indicadores. Quem escreve é
 * App\Services\Surgery\PostOp\PostOpEvaluationService (sob a trava da linha).
 *
 * @property PostOpMilestone        $milestone
 * @property SurgeryEye             $eye
 * @property PostOpEvaluationStatus $status
 * @property PostOpAdherence|null   $adherence
 * @property list<string>           $findings
 */
class SurgicalPostopEvaluation extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasAuditColumns;
    use HasUuids;

    /** Campos do conteúdo clínico: congelados ao finalizar e cobertos pelo hash. */
    public const CLINICAL = [
        'milestone', 'eye', 'evaluated_at', 'va_uncorrected_id', 'va_corrected_id', 'iop_mmhg', 'findings', 'findings_notes',
        'refraction_sphere', 'refraction_cylinder', 'refraction_axis', 'spherical_equivalent', 'complications', 'adherence',
        'plan', 'severe', 'doctor_id', 'schedule_id',
    ];

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'patient_id',
        'schedule_id',
        'doctor_id',
        'milestone',
        'eye',
        'evaluated_at',
        'va_uncorrected_id',
        'va_corrected_id',
        'iop_mmhg',
        'findings',
        'findings_notes',
        'refraction_sphere',
        'refraction_cylinder',
        'refraction_axis',
        'spherical_equivalent',
        'complications',
        'adherence',
        'plan',
        'severe',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $evaluation): void {
            if ($evaluation->getRawOriginal('status') === PostOpEvaluationStatus::Finalized->value) {
                throw new LogicException('Avaliação pós-operatória finalizada é imutável: use um adendo.');
            }
        });

        static::deleting(function (self $evaluation): void {
            if ($evaluation->getRawOriginal('status') === PostOpEvaluationStatus::Finalized->value) {
                throw new LogicException('Avaliação pós-operatória finalizada não pode ser apagada.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'milestone'            => PostOpMilestone::class,
            'eye'                  => SurgeryEye::class,
            'status'               => PostOpEvaluationStatus::class,
            'adherence'            => PostOpAdherence::class,
            'evaluated_at'         => 'datetime',
            'finalized_at'         => 'datetime',
            'findings'             => 'array',
            'severe'               => 'boolean',
            'iop_mmhg'             => 'decimal:1',
            'refraction_sphere'    => 'decimal:2',
            'refraction_cylinder'  => 'decimal:2',
            'spherical_equivalent' => 'decimal:2',
            'refraction_axis'      => 'integer',
            'revision'             => 'integer',
        ];
    }

    public function isFinalized(): bool
    {
        return $this->status === PostOpEvaluationStatus::Finalized;
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function vaUncorrected(): BelongsTo
    {
        return $this->belongsTo(VisualAcuityType::class, 'va_uncorrected_id');
    }

    public function vaCorrected(): BelongsTo
    {
        return $this->belongsTo(VisualAcuityType::class, 'va_corrected_id');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(SurgicalPostopEvaluationAddendum::class, 'surgical_postop_evaluation_id')->orderBy('created_at');
    }

    /** Achados já como enum (códigos desconhecidos são ignorados). @return list<PostOpFinding> */
    public function findingEnums(): array
    {
        return array_values(array_filter(array_map(
            fn ($code) => PostOpFinding::tryFrom((string) $code),
            (array) $this->findings,
        )));
    }
}
