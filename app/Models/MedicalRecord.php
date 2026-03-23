<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns, Versionable};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Factories\HasFactory, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecord extends Model
{
    // use HasFactory;
    use HasAuditColumns;
    use Auditable;
    use Versionable;
    use HasUuids;
    use SoftDeletes;

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
        'visual_acuity_type_id',
        'near_point_convergence_id',
        'cover_test_type_id',
        'visual_acuity_without_correction_right_id',
        'visual_acuity_without_correction_left_id',
        'visual_acuity_with_correction_right_id',
        'visual_acuity_with_correction_left_id',
        'addition_type_id',
        'lens_away_id',
        'lens_near_id',
        'code',
        'main_complaint',
        'diabetic',
        'diabetic_family',
        'hypertensive',
        'hypertensive_family',
        'glaucomatous',
        'glaucomatous_family',
        'tonometer_right',
        'tonometer_left',
        'tonometer_time',
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
        'static_axis_left',
        'biomicroscopy_right',
        'biomicroscopy_left',
        'fundoscopy_right',
        'fundoscopy_left',
        'observation_general',
        'observation_of_lenses',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $record) {
            if (blank($record->code)) {
                $prefix   = 'PMR';
                $entityId = Schedule::find($record->schedule_id)?->entity_id
                    ?? session('selected_entity_id');

                $lastRecord = static::withoutGlobalScopes()
                    ->whereHas('schedule', fn ($q) => $q->where('entity_id', $entityId))
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                $newNumber = $lastRecord
                    ? (int) substr($lastRecord->code, strlen($prefix) + 1) + 1
                    : 1;

                $record->code = sprintf('%s-%010d', $prefix, $newNumber);
            }
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
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
}
