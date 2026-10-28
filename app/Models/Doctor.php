<?php

namespace App\Models;

use App\Support\ScheduleShift;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Doctor extends Model
{
    use Auditable;
    use HasAuditColumns;
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
        'cbo_code',
        'color',
        'partner',
        'active',
        'observation',
        'schedule_interval',
        'fit_in_limit_morning',
        'fit_in_limit_afternoon',
        'fit_in_limit_evening',
    ];

    /** Teto padrão de encaixes por turno (decisão do dono; 0 = não aceita). */
    public const DEFAULT_FIT_IN_LIMIT = 2;

    /** Maior teto configurável por turno. */
    public const MAX_FIT_IN_LIMIT = 20;

    /**
     * Generated code for the entity_id field.
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
            'schedule_interval'      => 'integer',
            'fit_in_limit_morning'   => 'integer',
            'fit_in_limit_afternoon' => 'integer',
            'fit_in_limit_evening'   => 'integer',
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime',
            'deleted_at'             => 'datetime',
        ];
    }

    public function entityUser(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'entity_user_id', 'id');
    }

    /**
     * Médicos da clínica. `doctors` não tem entity_id: o vínculo com a clínica
     * é entity_user_id -> entity_users.entity_id (where('entity_id') aqui é
     * erro SQL, não filtro).
     *
     * @param Builder<Doctor> $query
     *
     * @return Builder<Doctor>
     */
    public function scopeOfEntity(Builder $query, string $entityId): Builder
    {
        return $query->whereHas('entityUser', fn (Builder $entityUser) => $entityUser->where('entity_id', $entityId));
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

    /**
     * Teto de encaixes do médico no turno (App\Support\ScheduleShift):
     * quantos encaixes não cancelados ele aceita por dia nesse turno. 0 = não
     * aceita encaixe.
     */
    public function fitInLimit(string $shift): int
    {
        $value = $this->getAttribute('fit_in_limit_' . $shift);

        return $value === null ? self::DEFAULT_FIT_IN_LIMIT : (int) $value;
    }

    /** @return array<string, int> teto por turno (morning/afternoon/evening) */
    public function fitInLimits(): array
    {
        return collect(ScheduleShift::ALL)
            ->mapWithKeys(fn (string $shift) => [$shift => $this->fitInLimit($shift)])
            ->all();
    }
}
