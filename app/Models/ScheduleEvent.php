<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ScheduleEvent extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'schedule_events';

    protected $fillable = [
        'entity_id',
        'doctor_id',
        'title',
        'type',
        'starts_at',
        'ends_at',
        'color',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
        ];
    }

    public static array $types = [
        'meeting'     => 'Reunião',
        'maintenance' => 'Manutenção',
        'personal'    => 'Pessoal',
        'training'    => 'Treinamento',
        'other'       => 'Outro',
    ];

    public function typeLabel(): string
    {
        return self::$types[$this->type] ?? 'Outro';
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'created_by');
    }

    /**
     * Resolves route model binding scoped to the current entity.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $query = static::where($field ?? $this->getRouteKeyName(), $value);

        if ($entityId = session('selected_entity_id')) {
            $query->where('entity_id', $entityId);
        }

        return $query->firstOrFail();
    }
}
