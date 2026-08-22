<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chamada de paciente pro painel/TV da sala de espera (opcional por clínica —
 * ver entities.call_panel_enabled). Nomes são snapshot do momento da chamada;
 * o painel público só lê daqui, nunca do cadastro.
 */
class PatientCall extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'entity_id',
        'schedule_id',
        'patient_name',
        'doctor_name',
        'called_by_entity_user_id',
        'created_at',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }
}
