<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\ChecklistPhase;
use App\Models\Concerns\BelongsToEntity;
use App\Models\{Doctor, User};
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use LogicException;

/**
 * Fase do checklist de cirurgia segura de um caso. Depois de FECHADA a linha
 * não muda mais (conteúdo, hash, quem fechou) e nunca é apagada; a única
 * escrita permitida em fase não fechada é abrir/fechar e registrar a exceção
 * da Pausa Cirúrgica (`override_*`).
 *
 * @property ChecklistPhase $phase
 */
class SurgicalChecklistPhase extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'phase',
        'catalog_version',
        'locale',
        'opened_at',
        'opened_by',
        'closed_at',
        'closed_by',
        'closed_by_doctor_id',
        'content_hash',
        'override_reason',
        'override_doctor_id',
        'override_at',
        'plan_fingerprint',
        'override_fingerprint',
    ];

    /** Fase fechada: só o registro da exceção do cirurgião pode mudar (o conteúdo respondido, nunca). */
    private const MUTABLE_WHEN_CLOSED = ['override_reason', 'override_doctor_id', 'override_at', 'override_fingerprint', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $phase): void {
            if ($phase->getOriginal('closed_at') !== null
                && array_diff(array_keys($phase->getDirty()), self::MUTABLE_WHEN_CLOSED) !== []) {
                throw new LogicException('Fase de checklist fechada é imutável.');
            }
        });

        static::deleting(function (self $phase): void {
            if ($phase->closed_at !== null) {
                throw new LogicException('Fase de checklist fechada não pode ser apagada.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'phase'       => ChecklistPhase::class,
            'opened_at'   => 'datetime',
            'closed_at'   => 'datetime',
            'override_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SurgicalChecklistEntry::class, 'surgical_checklist_phase_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function overrideDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'override_doctor_id');
    }
}
