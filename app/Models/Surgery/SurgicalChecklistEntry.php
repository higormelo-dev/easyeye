<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\{ChecklistAnswer, ChecklistPhase};
use App\Models\Concerns\BelongsToEntity;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Resposta de um item do checklist. Só muda enquanto a fase está aberta;
 * com a fase fechada, atualizar ou apagar é recusado (o conteúdo respondido
 * entrou no hash do fechamento).
 *
 * @property ChecklistPhase  $phase
 * @property ChecklistAnswer $answer
 */
class SurgicalChecklistEntry extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'surgical_checklist_phase_id',
        'phase',
        'item_key',
        'item_text',
        'critical',
        'answer',
        'note',
        'answered_by',
        'answered_at',
    ];

    protected static function booted(): void
    {
        $guard = function (self $entry): void {
            $closed = SurgicalChecklistPhase::query()
                ->withoutGlobalScopes()
                ->whereKey($entry->surgical_checklist_phase_id)
                ->whereNotNull('closed_at')
                ->exists();

            if ($closed) {
                throw new LogicException('Resposta de checklist de fase fechada é imutável.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
        static::creating($guard);
    }

    protected function casts(): array
    {
        return [
            'phase'       => ChecklistPhase::class,
            'answer'      => ChecklistAnswer::class,
            'critical'    => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function checklistPhase(): BelongsTo
    {
        return $this->belongsTo(SurgicalChecklistPhase::class, 'surgical_checklist_phase_id');
    }
}
