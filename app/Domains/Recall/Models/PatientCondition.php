<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus};
use App\Enums\Eye;
use App\Models\Concerns\BelongsToEntity;
use App\Models\Patient;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Uma condição crônica de UM olho do paciente que dispara um protocolo de retorno (glaucoma, DMRI...).
 * Nasce `suggested` (detectada, ninguém confirmou: não gera convite) ou `active`; o médico confirma, descarta
 * ou dá alta — ato clínico (gate DecideRecall). Toda mudança vai para audit_logs (Auditable): dado de saúde.
 *
 * `evidence` guarda só ids, códigos e datas (qual prontuário, qual CID, qual medicação) — nunca texto livre.
 *
 * @property string          $id
 * @property string          $entity_id
 * @property string          $patient_id
 * @property string          $protocol_key
 * @property Eye             $eye
 * @property ConditionStatus $status
 * @property ConditionOrigin $origin
 */
class PatientCondition extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = [
        'entity_id', 'patient_id', 'protocol_key', 'eye', 'status', 'origin', 'started_on', 'evidence',
        'confirmed_at', 'confirmed_by', 'discharged_at', 'discharged_by', 'discharge_reason',
        'rejected_at', 'rejected_by',
    ];

    protected function casts(): array
    {
        return [
            'eye'           => Eye::class,
            'status'        => ConditionStatus::class,
            'origin'        => ConditionOrigin::class,
            'started_on'    => 'date',
            'evidence'      => 'array',
            'confirmed_at'  => 'datetime',
            'discharged_at' => 'datetime',
            'rejected_at'   => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recalls(): HasMany
    {
        return $this->hasMany(PatientRecall::class, 'patient_condition_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ConditionStatus::Active->value);
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [ConditionStatus::Suggested->value, ConditionStatus::Active->value]);
    }
}
