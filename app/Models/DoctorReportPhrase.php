<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frase rápida do médico pro laudo do Gerenciador de Imagens — ver
 * migration create_doctor_report_phrases_table para o porquê da tabela
 * nascer vazia (sem terminologia clínica pré-cadastrada).
 */
class DoctorReportPhrase extends Model
{
    use Auditable;
    use HasUuids;

    protected $fillable = [
        'doctor_id',
        'entity_id',
        'label',
        'content',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position'   => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
