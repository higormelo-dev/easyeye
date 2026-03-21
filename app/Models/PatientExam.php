<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PatientExam extends Model
{
    use HasFactory;
    use HasUuids;

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
        'entity_integrator_equipment_id',
        'exam_id',
        'code',
        'archive',
        'name',
        'active',
    ];

    /**
     * @var string[]
     */
    protected $appends = ['archive_url'];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $patientExam) {
            if (blank($patientExam->code)) {
                $prefix = 'EXM';

                $lastExam = static::withoutGlobalScopes()
                    ->where('patient_id', $patientExam->patient_id)
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastExam) {
                    $lastNumber = (int) substr($lastExam->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $patientExam->code = sprintf('%s-%010d', $prefix, $newNumber);
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

    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'exam_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(EntityIntegratorEquipment::class, 'entity_integrator_equipment_id');
    }

    public function archiveUrl(): Attribute
    {
        return new Attribute(
            get: fn () => $this->archive ? Storage::disk('s3')->temporaryUrl($this->archive, now()->addDay(1)) : null,
        );
    }
}
