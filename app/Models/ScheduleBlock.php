<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleBlock extends Model
{
    use HasUuids;

    protected $fillable = [
        'doctor_id',
        'starts_at',
        'ends_at',
        'reason',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public static function typeLabels(): array
    {
        return [
            'absence' => 'Ausência',
            'holiday' => 'Feriado',
            'meeting' => 'Reunião / Compromisso',
            'other'   => 'Outro',
        ];
    }

    public function typeLabel(): string
    {
        return static::typeLabels()[$this->type] ?? $this->type;
    }
}
