<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class Doctor extends Model
{
    use HasAuditColumns;
    use Auditable;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_user_id',
        'person_id',
        'code',
        'record',
        'record_specialty',
        'color',
        'partner',
        'active',
        'observation',
        'schedule_interval',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $doctor) {
            if (blank($doctor->code)) {
                $prefix = 'DOC';

                $lastType = static::withoutGlobalScopes()
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastType) {
                    $lastNumber = (int) substr($lastType->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $doctor->code = sprintf('%s-%010d', $prefix, $newNumber);
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
            'schedule_interval' => 'integer',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'deleted_at'        => 'datetime',
        ];
    }

    public function entityUser(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'entity_user_id', 'id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'person_id', 'id');
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(DoctorWorkSchedule::class)->orderBy('day_of_week')->orderBy('starts_at');
    }

    public function scheduleBlocks(): HasMany
    {
        return $this->hasMany(ScheduleBlock::class)->orderBy('starts_at');
    }

    /**
     * Returns the effective consultation interval in minutes.
     * Falls back to entity setting, then to 15 minutes.
     */
    public function effectiveInterval(): int
    {
        if ($this->schedule_interval !== null) {
            return $this->schedule_interval;
        }

        return $this->entityUser->entity->schedule_interval ?? 15;
    }
}
