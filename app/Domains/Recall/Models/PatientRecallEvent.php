<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * O que aconteceu com um retorno (calculado, contatado, adiado, dispensado, agendado, encerrado...). APPEND-ONLY:
 * o histórico é a explicação do que a clínica fez e do que o sistema decidiu; nunca se reescreve.
 */
class PatientRecallEvent extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['entity_id', 'patient_id', 'recall_id', 'event', 'actor_user_id', 'actor_label', 'meta'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('patient_recall_events é append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('patient_recall_events é append-only.');
        });
    }

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function recall(): BelongsTo
    {
        return $this->belongsTo(PatientRecall::class, 'recall_id');
    }
}
