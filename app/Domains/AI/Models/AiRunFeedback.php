<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Models\{Doctor, Entity};
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feedback do médico após edição pesada (>30%) do rascunho da IA. Vira dataset
 * estruturado para análise de qualidade e fine-tuning.
 */
class AiRunFeedback extends Model
{
    use Auditable;
    use HasUuids;

    protected $table = 'ai_run_feedbacks';

    protected $fillable = [
        'ai_run_id',
        'entity_id',
        'doctor_id',
        'edit_ratio_percent',
        'tags',
        'note',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'edit_ratio_percent' => 'integer',
            'tags'               => 'array',
            'submitted_at'       => 'datetime',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
        ];
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }
}
