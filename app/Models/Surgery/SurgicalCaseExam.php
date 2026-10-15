<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use App\Models\PatientExam;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exame de apoio vinculado ao caso (biometria, topografia, OCT...). */
class SurgicalCaseExam extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    public const ROLES = ['biometry', 'topography', 'oct', 'other'];

    protected $fillable = ['entity_id', 'surgical_case_id', 'patient_exam_id', 'role', 'notes'];

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(PatientExam::class, 'patient_exam_id');
    }
}
