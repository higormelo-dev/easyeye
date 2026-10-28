<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exame de um kit (Fase 2 / B2): código TUSS + nome. `procedure_id` só em kit
 * da clínica, quando o item foi escolhido do catálogo dela. As alterações do
 * kit vão para a trilha do ExamKit (os itens são regravados juntos).
 */
class ExamKitItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'exam_kit_id',
        'tuss_code',
        'name',
        'procedure_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(ExamKit::class, 'exam_kit_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
