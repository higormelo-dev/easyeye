<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Casts\Attribute, Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WaitingList extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'waiting_list';

    protected $fillable = [
        'entity_id',
        'doctor_id',
        'patient_id',
        'full_name',
        'telephone',
        'cellphone',
        'cellphone_whatsapp',
        'covenant_id',
        'visit_id',
        'notes',
        'preferred_date_from',
        'preferred_date_until',
        'position',
        'schedule_id',
        'scheduled_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date_from'  => 'date',
            'preferred_date_until' => 'date',
            'scheduled_at'         => 'datetime',
            'active'               => 'boolean',
            'cellphone_whatsapp'   => 'boolean',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function visitType(): BelongsTo
    {
        return $this->belongsTo(VisitType::class, 'visit_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value !== null
                ? mb_convert_case($value, MB_CASE_UPPER, 'UTF-8')
                : null,
        );
    }
}
