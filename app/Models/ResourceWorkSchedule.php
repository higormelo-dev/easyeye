<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo};
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ResourceWorkSchedule extends Model
{
    use HasUuids;

    protected $table = 'resource_work_schedules';

    protected $fillable = [
        'resource_id',
        'day_of_week',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public static function dayName(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            0 => 'Domingo',
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
        };
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ClinicResource::class, 'resource_id');
    }
}
