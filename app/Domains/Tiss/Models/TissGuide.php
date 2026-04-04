<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Domains\Tiss\Enums\{TissGuideStatus, TissGuideType};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, Schedule};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\BelongsToMany, Relations\HasMany, SoftDeletes};

class TissGuide extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_guides';

    protected $fillable = [
        'entity_id',
        'operator_id',
        'contract_id',
        'version_id',
        'patient_id',
        'doctor_id',
        'schedule_id',
        'medical_record_id',
        'guide_type',
        'guide_number_provider',
        'guide_number_operator',
        'external_attendance_id',
        'status',
        'attendance_date',
        'execution_date',
        'due_date',
        'beneficiary_card_number',
        'beneficiary_name',
        'beneficiary_plan',
        'authorization_number',
        'clinical_indication',
        'total_amount',
        'denied_amount',
        'paid_amount',
        'payload',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'guide_type'      => TissGuideType::class,
            'status'          => TissGuideStatus::class,
            'attendance_date' => 'date',
            'execution_date'  => 'date',
            'due_date'        => 'date',
            'total_amount'    => 'decimal:2',
            'denied_amount'   => 'decimal:2',
            'paid_amount'     => 'decimal:2',
            'payload'         => 'array',
            'errors'          => 'array',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(TissOperator::class, 'operator_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(TissEntityOperatorContract::class, 'contract_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TissVersion::class, 'version_id');
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

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TissGuideItem::class, 'guide_id');
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(TissBatch::class, 'tiss_batch_guides', 'guide_id', 'batch_id')
            ->withPivot(['id', 'entity_id', 'attached_at'])
            ->withTimestamps();
    }

    public function parentLinks(): HasMany
    {
        return $this->hasMany(TissGuideLink::class, 'parent_guide_id');
    }

    public function childLinks(): HasMany
    {
        return $this->hasMany(TissGuideLink::class, 'child_guide_id');
    }

    public function glosas(): HasMany
    {
        return $this->hasMany(TissGlosa::class, 'guide_id');
    }
}
