<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Canal de comando do backend pro desktop (Rust) — ver o doc comment da
 * migration `create_integrator_commands_table` pro modelo de entrega
 * (at-least-once, execute-então-ack).
 */
class IntegratorCommand extends Model
{
    use HasUuids;

    public static function deadlineSql(): string
    {
        // Legacy created_at is timestamp WITHOUT timezone; do not let the DB session reinterpret it.
        $timezone = DB::connection()->getPdo()->quote((string) config('app.timezone'));

        return "COALESCE(expires_at, (created_at AT TIME ZONE {$timezone}) + interval '1 hour')";
    }

    protected $table = 'integrator_commands';

    // expires_at/acked_at are timestamptz, whereas legacy created_at is naive.
    // Include offsets on writes so the connection's timezone cannot move deadlines.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'expires_at', 'requested_by',
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
            'expires_at' => 'datetime',
            'payload'    => 'array',
            'result'     => 'array',
            'acked_at'   => 'datetime',
        ];
    }

    public function integrator(): BelongsTo
    {
        return $this->belongsTo(EntityIntegrator::class, 'integrator_id', 'id');
    }
}
