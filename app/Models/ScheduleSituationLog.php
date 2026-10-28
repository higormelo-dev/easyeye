<?php

namespace App\Models;

use App\Enums\ScheduleSituation;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleSituationLog extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'schedule_id',
        'entity_user_id',
        'from_situation',
        'to_situation',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_situation' => ScheduleSituation::class,
            'to_situation'   => ScheduleSituation::class,
            'created_at'     => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function entityUser(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class);
    }
}
