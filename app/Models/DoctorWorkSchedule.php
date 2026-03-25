<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorWorkSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'doctor_id',
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

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public static function dayName(int $dayOfWeek): string
    {
        return __('actions.weekdays')[$dayOfWeek] ?? '?';
    }
}
