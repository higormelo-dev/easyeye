<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preset de prescrição do médico pra um medicamento: "minha posologia",
 * favorito e contadores de uso (abas Recentes | Favoritos do receituário).
 * Escopo por entity_user — ver migration.
 */
class DoctorMedicationPreset extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'entity_user_id',
        'medicine_id',
        'posology',
        'is_favorite',
        'usage_count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_favorite'  => 'boolean',
            'usage_count'  => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id');
    }
}
