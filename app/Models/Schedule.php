<?php

namespace App\Models;

use App\Enums\ScheduleSituation;
use App\Presenters\SchedulePresenter;
use Illuminate\Database\Eloquent\{Casts\Attribute, Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laracasts\Presenter\PresentableTrait;

class Schedule extends Model
{
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
        'situation',
        'arrived_at',
        'active',
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
            'situation'  => ScheduleSituation::class,
            'arrived_at' => 'datetime',
            'date_time'  => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
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

    protected function fullName(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value !== null
                ? mb_convert_case($value, MB_CASE_UPPER, 'UTF-8')
                : null,
        );
    }
}
