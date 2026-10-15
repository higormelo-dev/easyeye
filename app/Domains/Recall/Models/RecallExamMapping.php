<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Models\ExamType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quais tipos de exame DA CLÍNICA contam como uma chave canônica do protocolo ("campimetria", "OCT de
 * mácula"...). O protocolo fala em chaves; cada clínica diz quais dos seus exames são essas.
 */
class RecallExamMapping extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['entity_id', 'exam_key', 'exam_type_id', 'created_by'];

    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }
}
