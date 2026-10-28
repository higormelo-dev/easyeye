<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma linha por sincronização (log de verdade) — ver o doc comment da
 * migration. Retenção de 7 dias via `queue-health:prune-history`
 * (routes/console.php), nunca lido/escrito fora desse comando e do
 * `IntegratorQueueHealthController::store`.
 */
class IntegratorQueueHealthHistory extends Model
{
    protected $table = 'integrator_queue_health_history';

    public $timestamps = false;

    protected $fillable = [
        'integrator_id',
        'pending_count',
        'failed_count',
        'blocked_count',
        'sent_last_24h_count',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function integrator()
    {
        return $this->belongsTo(EntityIntegrator::class, 'integrator_id', 'id');
    }
}
