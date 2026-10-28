<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Models;

use App\Domains\Recall\Intravitreal\Enums\{CourseRegimen, CourseStatus};
use App\Models\Concerns\BelongsToEntity;
use App\Models\{Doctor, Patient};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * O tratamento de UM olho com injeções intravítreas (anti-VEGF): droga, esquema, o intervalo que o médico decidiu na
 * última aplicação ("trate e estenda") e a próxima aplicação. Um curso aberto (ativo/pausado) por paciente e olho
 * (índice único parcial). Dado de saúde: Auditable + soft delete; nunca é apagado pela tela — encerra com motivo.
 *
 * @property string        $id
 * @property string        $entity_id
 * @property string        $patient_id
 * @property string        $eye
 * @property string        $drug_key
 * @property string        $drug_label
 * @property CourseRegimen $regimen
 * @property CourseStatus  $status
 */
class IntravitrealCourse extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id', 'patient_id', 'eye', 'drug_key', 'drug_label', 'indication', 'regimen', 'loading_doses_planned',
        'min_interval_weeks', 'max_interval_weeks', 'current_interval_weeks', 'status', 'started_on', 'ended_on',
        'end_reason', 'next_due_on', 'last_applied_on', 'responsible_doctor_id', 'series_id',
    ];

    protected function casts(): array
    {
        return [
            'regimen'                => CourseRegimen::class,
            'status'                 => CourseStatus::class,
            'started_on'             => 'date',
            'ended_on'               => 'date',
            'next_due_on'            => 'date',
            'last_applied_on'        => 'date',
            'loading_doses_planned'  => 'integer',
            'min_interval_weeks'     => 'integer',
            'max_interval_weeks'     => 'integer',
            'current_interval_weeks' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function responsibleDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'responsible_doctor_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(IntravitrealApplication::class, 'course_id')->orderBy('sequence_no');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [CourseStatus::Active->value, CourseStatus::Paused->value]);
    }
}
