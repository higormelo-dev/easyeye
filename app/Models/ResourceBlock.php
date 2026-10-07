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
        // Traduzido (lang/*/resource_schedule.php) — a tela "Escala e
        // bloqueios" do recurso lista e exibe estes rótulos.
        return [
            'absence' => __('resource_schedule.block_types.absence'),
            'holiday' => __('resource_schedule.block_types.holiday'),
            'meeting' => __('resource_schedule.block_types.meeting'),
            'other'   => __('resource_schedule.block_types.other'),
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
