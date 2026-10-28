<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um grupo do lote de posologia por IA: itens iguais (princípio ativo +
 * concentração + forma) que recebem a MESMA sugestão de UMA chamada de IA.
 */
class MedicinePosologyBatchGroup extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    /** Posologia gravada nos itens (ainda sem posologia) do grupo. */
    public const STATUS_DONE = 'done';

    /** IA falhou ou respondeu sem posologia utilizável. */
    public const STATUS_FAILED = 'failed';

    /** Todos os itens ganharam posologia antes da chamada (nenhuma chamada feita). */
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'batch_id',
        'position',
        'group_key',
        'label',
        'medicine_ids',
        'status',
        'updated_count',
        'skipped_count',
        'attempts',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'medicine_ids' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicinePosologyBatch::class, 'batch_id');
    }
}
