<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivationStep;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityActivation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'step',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'step'         => ActivationStep::class,
            'completed_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
