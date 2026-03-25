<?php

namespace App\Models;

use App\Enums\{PatientMood, ScheduleSituation};
use App\Presenters\SchedulePresenter;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Casts\Attribute, Model, Relations\BelongsTo, Relations\BelongsToMany, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laracasts\Presenter\PresentableTrait;

class Schedule extends Model
{
    use HasAuditColumns;
    use Auditable;
    use HasUuids;
    use SoftDeletes;
    use PresentableTrait;

    protected $presenter = SchedulePresenter::class;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'doctor_id',
        'patient_id',
        'covenant_id',
        'visit_id',
        'code',
        'full_name',
        'date_time',
        'telephone',
        'cellphone',
        'cellphone_whatsapp',
        'notes',
        'cancellation_reason',
        'situation',
        'arrived_at',
        'confirmed_at',
        'patient_mood',
        'active',
        'recurrence_group_id',
        'recurrence_type',
        'recurrence_until',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $schedule) {
            if (blank($schedule->code)) {
                $prefix = 'SDL';

                $lastSchedule = static::withoutGlobalScopes()
                    ->where('entity_id', $schedule->entity_id)
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastSchedule) {
                    $lastNumber = (int) substr($lastSchedule->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $schedule->code = sprintf('%s-%010d', $prefix, $newNumber);
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
            'situation'    => ScheduleSituation::class,
            'patient_mood' => PatientMood::class,
            'arrived_at'  => 'datetime',
            'confirmed_at' => 'datetime',
            'date_time'   => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
            'deleted_at'  => 'datetime',
        ];
    }

    /**
     * Scope route model binding to the current entity (tenant guard).
     * Prevents users of one clinic from accessing another clinic's schedules
     * via URL manipulation.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $query = static::where($field ?? $this->getRouteKeyName(), $value);

        if ($entityId = session('selected_entity_id')) {
            $query->where('entity_id', $entityId);
        }

        return $query->firstOrFail();
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

    public function situationLogs(): HasMany
    {
        return $this->hasMany(ScheduleSituationLog::class)->orderBy('created_at');
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(ClinicResource::class, 'schedule_resources');
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
