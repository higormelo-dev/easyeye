<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\BelongsToMany, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ClinicResource extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'clinic_resources';

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'type',
        'description',
        'active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $resource) {
            if (blank($resource->code)) {
                $prefix = 'REC';

                $last = static::withoutGlobalScopes()
                    ->where('entity_id', $resource->entity_id)
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                $number = $last
                    ? (int) substr($last->code, strlen($prefix) + 1) + 1
                    : 1;

                $resource->code = sprintf('%s-%010d', $prefix, $number);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'active'     => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public static function typeLabels(): array
    {
        return [
            'room'      => 'Sala',
            'equipment' => 'Equipamento',
        ];
    }

    public function typeLabel(): string
    {
        return static::typeLabels()[$this->type] ?? $this->type;
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(ResourceWorkSchedule::class, 'resource_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ResourceBlock::class, 'resource_id');
    }

    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(Schedule::class, 'schedule_resources');
    }
}
