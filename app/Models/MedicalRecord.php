<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns, Signable, Versionable};
use DB;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Factories\HasFactory, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class MedicalRecord extends Model
{
    use Auditable;

    // use HasFactory;
    use HasAuditColumns;
    use HasUuids;
    use Signable;
    use SoftDeletes;
    use Versionable;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'doctor_id',
        'schedule_id',
        'entity_id',
        // Exame físico — seleções
        'visual_acuity_type_id',
        'near_point_convergence_id',
        'cover_test_type_id',
        'color_vision_type_id',        // CBO: visão cromática (campo corrigido)
        'visual_acuity_without_correction_right_id',
        'visual_acuity_without_correction_left_id',
        'visual_acuity_with_correction_right_id',
        'visual_acuity_with_correction_left_id',
        'addition_type_id',
        'lens_away_id',
        'lens_near_id',
        'code',
        // Anamnese — CBO
        'main_complaint',
        'hda',                         // CBO: história da doença atual
        'others_history',              // Outros antecedentes relevantes
        'diabetic',
        'diabetic_family',
        'hypertensive',
        'hypertensive_family',
        'glaucomatous',
        'glaucomatous_family',
        'ocular_surgical_history',     // CBO: antecedentes cirúrgicos oculares
        'medications_in_use',          // CBO: medicamentos em uso
        // Exame físico — texto
        'ocular_motility',             // CBO: motilidade ocular extrínseca
        'tonometer_right',
        'tonometer_left',
        'tonometer_time',
        'pachymetry_right',            // CBO: paquimetria OD (μm)
        'pachymetry_left',             // CBO: paquimetria OE (μm)
        'gonioscopy_right',            // CBO: gonioscopia OD
        'gonioscopy_left',             // CBO: gonioscopia OE
        'dynamic_spherical_right',
        'dynamic_spherical_left',
        'dynamic_cylindrical_right',
        'dynamic_cylindrical_left',
        'dynamic_axis_right',
        'dynamic_axis_left',
        'static_spherical_right',
        'static_spherical_left',
        'static_cylindrical_right',
        'static_cylindrical_left',
        'static_axis_right',
        'static_axis_left',
        'biomicroscopy_right',
        'biomicroscopy_left',
        'fundoscopy_right',
        'fundoscopy_left',
        'observation_general',
        'observation_of_lenses',
        // Diagnóstico — CBO obrigatório (múltiplos CIDs como JSON [{code, description}])
        'diagnosis_cids',
        // Conduta — CBO obrigatório
        'clinical_conduct',            // CBO: conduta clínica / plano terapêutico
        'follow_up_days',              // CBO: retorno em dias
        // Assinatura — CFM Res. 2.227/2018
        'signed_by',
        'signed_at',
        'signature_hash',
        'is_locked',
    ];

    /**
     * Resolve entity_id + gera code sequencial por entidade.
     *
     * Multi-tenancy correto: entity_id é coluna direta (não derivada via
     * schedule), permitindo prontuário sem agenda (walk-in/admin SaaS).
     *
     * Code generator usa transaction + lockForUpdate na última row do mesmo
     * prefix/entity para mitigar race em saves concorrentes (clínica
     * pequena, baixo throughput — lock pessimista é suficiente).
     *
     * Sem entity_id resolvível, fallback global (legado / fixtures de teste).
     */
    protected static function booted(): void
    {
        static::creating(function (self $record) {
            if (blank($record->entity_id)) {
                $record->entity_id = Schedule::find($record->schedule_id)?->entity_id
                    ?? session('selected_entity_id');
            }

            if (blank($record->code)) {
                $record->code = self::generateCodeForEntity(
                    blank($record->entity_id) ? null : (string) $record->entity_id,
                );
            }
        });
    }

    /**
     * Gera próximo code sequencial `PMR-NNNNNNNNNN`.
     * Filtra por entity quando id presente; fallback global caso contrário.
     */
    private static function generateCodeForEntity(?string $entityId): string
    {
        $prefix = 'PMR';

        return DB::transaction(function () use ($prefix, $entityId) {
            $query = static::withoutGlobalScopes()
                ->where('code', 'like', $prefix . '-%')
                ->orderByDesc('code')
                ->lockForUpdate();

            if ($entityId !== null) {
                $query->where('entity_id', $entityId);
            }

            $lastRecord = $query->first();

            $newNumber = $lastRecord
                ? (int) substr($lastRecord->code, strlen($prefix) + 1) + 1
                : 1;

            return sprintf('%s-%010d', $prefix, $newNumber);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
            'signed_at'      => 'datetime',
            'is_locked'      => 'boolean',
            'diagnosis_cids' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function visualAcuityType(): BelongsTo
    {
        return $this->belongsTo(VisualAcuityType::class, 'visual_acuity_type_id');
    }

    public function NearPointConvergence(): BelongsTo
    {
        return $this->belongsTo(NearPointConvergence::class, 'near_point_convergence_id');
    }

    public function coverTestType(): BelongsTo
    {
        return $this->belongsTo(CoverTestType::class, 'cover_test_type_id');
    }

    public function colorVisionType(): BelongsTo
    {
        return $this->belongsTo(ColorVisionType::class, 'color_vision_type_id');
    }

    public function visualAcuityTypeWithoutCorrectionRight(): BelongsTo
    {
        return $this->belongsTo(VisualAcuityType::class, 'visual_acuity_without_correction_right_id');
    }

    public function visualAcuityTypeWithoutCorrectionLeft(): BelongsTo
    {
        return $this->belongsTo(VisualAcuityType::class, 'visual_acuity_without_correction_left_id');
    }

    public function visualAcuityTypeWitCorrectionRight(): BelongsTo
    {
        return $this->belongsTo(VisualAcuityType::class, 'visual_acuity_with_correction_right_id');
    }

    public function visualAcuityTypeWitCorrectionLeft(): BelongsTo
    {
        return $this->belongsTo(VisualAcuityType::class, 'visual_acuity_with_correction_left_id');
    }

    public function additionType(): BelongsTo
    {
        return $this->belongsTo(AdditionType::class, 'addition_type_id');
    }

    public function lensAway(): BelongsTo
    {
        return $this->belongsTo(Lense::class, 'lens_away_id');
    }

    public function lensNear(): BelongsTo
    {
        return $this->belongsTo(Lense::class, 'lens_near_id');
    }

    public function documentations(): HasMany
    {
        return $this->hasMany(MedicalRecordDocumentation::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(MedicalRecordFile::class);
    }

    /** EntityUser que assinou o prontuário (CFM Res. 2.227/2018) */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'signed_by');
    }
}
