<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Domains\Recall\Engine\RecallState;
use App\Domains\Recall\Enums\RecallLifecycle;
use App\Enums\Eye;
use App\Models\Concerns\BelongsToEntity;
use App\Models\{Patient, Schedule};
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * O retorno pendente de um paciente — DERIVADO (ADR 0002, item 7): reavaliado a qualquer momento pelo
 * avaliador a partir do prontuário e dos protocolos adotados; não é fonte da verdade e NÃO é auditado
 * linha a linha (o que importa fica em `patient_recall_events`). Uma linha por condição+tarefa (reaproveitada
 * a cada ciclo) e uma por paciente para o retorno pedido pelo médico.
 *
 * Datas persistidas (`due_at`, `invite_from`, `overdue_from`, `lost_from`) fazem a lista ser um SELECT
 * indexado; o ESTADO do prazo (a vencer, vencido, perdido) é calculado com o dia de hoje (`state()`).
 *
 * @property string          $id
 * @property string          $entity_id
 * @property string          $patient_id
 * @property string          $source
 * @property RecallLifecycle $lifecycle
 * @property ?Eye            $eye
 */
class PatientRecall extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const SOURCE_PROTOCOL = 'protocol';

    public const SOURCE_DOCTOR_ORDER = 'doctor_order';

    public const SOURCE_COURSE = 'course';

    protected $fillable = [
        'entity_id', 'patient_id', 'source', 'patient_condition_id', 'protocol_key', 'protocol_version_id',
        'task_key', 'kind', 'exam_key', 'eye', 'last_done_at', 'anchor_at', 'due_at', 'invite_from', 'overdue_from',
        'lost_from', 'lifecycle', 'closed_reason', 'snoozed_until', 'dismissed_reason', 'rule_suspended',
        'last_contact_at', 'last_contact_outcome', 'contact_count', 'external_done_at', 'external_done_by', 'scheduled_schedule_id',
        'evaluator_version', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'eye'               => Eye::class,
            'lifecycle'         => RecallLifecycle::class,
            'last_done_at'      => 'date',
            'anchor_at'         => 'date',
            'due_at'            => 'date',
            'invite_from'       => 'date',
            'overdue_from'      => 'date',
            'lost_from'         => 'date',
            'snoozed_until'     => 'date',
            'external_done_at'  => 'date',
            'rule_suspended'    => 'boolean',
            'last_contact_at'   => 'datetime',
            'contact_count'     => 'integer',
            'evaluator_version' => 'integer',
            'meta'              => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(PatientCondition::class, 'patient_condition_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'scheduled_schedule_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PatientRecallEvent::class, 'recall_id');
    }

    /** O estado do prazo hoje (nulo quando não há datas: falta dado ou tarefa já concluída). */
    public function state(CarbonInterface $today): ?RecallState
    {
        if ($this->due_at === null || $this->invite_from === null || $this->overdue_from === null || $this->lost_from === null) {
            return null;
        }

        return RecallState::at($today, $this->invite_from, $this->due_at, $this->overdue_from, $this->lost_from);
    }

    public function isDoctorOrder(): bool
    {
        return $this->source === self::SOURCE_DOCTOR_ORDER;
    }

    /** A próxima aplicação de um curso de injeções intravítreas (não vem de protocolo nem de pedido do médico). */
    public function isCourse(): bool
    {
        return $this->source === self::SOURCE_COURSE;
    }

    /** @param Builder<self> $query */
    public function scopeWorkable(Builder $query): Builder
    {
        return $query->whereIn('lifecycle', [RecallLifecycle::Open->value, RecallLifecycle::Snoozed->value]);
    }
}
