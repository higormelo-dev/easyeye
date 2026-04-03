<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo};

class ResourceBlock extends Model
{
    use HasUuids;

    protected $table = 'resource_blocks';

    protected $fillable = [
        'resource_id',
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

    public static function typeLabels(): array
    {
        return [
            'absence' => 'Indisponível',
            'holiday' => 'Feriado',
            'meeting' => 'Manutenção',
            'other'   => 'Outro',
        ];
    }

    public function typeLabel(): string
    {
        return static::typeLabels()[$this->type] ?? $this->type;
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ClinicResource::class, 'resource_id');
    }
}
