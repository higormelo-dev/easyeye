<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiProvider;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCircuitBreaker extends Model
{
    use HasUuids;

    protected $table = 'ai_circuit_breakers';

    protected $fillable = [
        'provider_code',
        'entity_id',
        'state',
        'failure_count',
        'failure_threshold',
        'last_trigger_type',
        'last_failure_at',
        'open_until',
    ];

    protected function casts(): array
    {
        return [
            'provider_code'     => AiProvider::class,
            'failure_count'     => 'integer',
            'failure_threshold' => 'integer',
            'last_failure_at'   => 'datetime',
            'open_until'        => 'datetime',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function isOpen(): bool
    {
        return $this->state === 'open'
            && $this->open_until !== null
            && $this->open_until->isFuture();
    }
}
