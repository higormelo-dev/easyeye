<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canal de comando do backend pro desktop (Rust) — ver o doc comment da
 * migration `create_integrator_commands_table` pro modelo de entrega
 * (at-least-once, execute-então-ack).
 */
class IntegratorCommand extends Model
{
    use HasUuids;

    protected $table = 'integrator_commands';

    protected $fillable = [
        'integrator_id',
        'type',
        'payload',
        'status',
        'result',
        'acked_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'   => 'array',
            'result'    => 'array',
            'acked_at'  => 'datetime',
        ];
    }

    public function integrator(): BelongsTo
    {
        return $this->belongsTo(EntityIntegrator::class, 'integrator_id', 'id');
    }
}
