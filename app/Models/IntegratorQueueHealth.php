<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Retrato do estado ATUAL da fila local do integrador — ver o doc comment
 * da migration para o porquê de ser upsert, nunca um histórico.
 */
class IntegratorQueueHealth extends Model
{
    protected $table = 'integrator_queue_health';

    protected $primaryKey = 'integrator_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'integrator_id',
        'pending_count',
        'failed_count',
        'blocked_count',
        'sent_last_24h_count',
        'problems',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'problems'  => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function integrator()
    {
        return $this->belongsTo(EntityIntegrator::class, 'integrator_id', 'id');
    }
}
