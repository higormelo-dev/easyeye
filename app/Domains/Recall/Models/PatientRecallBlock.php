<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Domains\Recall\Enums\RecallBlockReason;
use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/** Quem NÃO deve ser contatado para retorno (óbito, pediu para não ser contatado, mudou, sem telefone válido). */
class PatientRecallBlock extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['entity_id', 'patient_id', 'reason', 'note', 'created_by', 'released_at', 'released_by'];

    protected function casts(): array
    {
        return ['reason' => RecallBlockReason::class, 'created_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
