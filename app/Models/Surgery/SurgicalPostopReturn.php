<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\{PostOpMilestone, PostOpReturnStatus};
use App\Models\Concerns\BelongsToEntity;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retorno PLANEJADO do pós-operatório (D1/D7/D30 ou urgente). É a chave de
 * idempotência da criação dos retornos: existe uma linha por caso + marco,
 * mesmo que a recepção cancele o agendamento — a reentrega do evento
 * "cirurgia realizada" nunca recria o que já existiu.
 *
 * @property PostOpMilestone    $milestone
 * @property PostOpReturnStatus $status
 */
class SurgicalPostopReturn extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'milestone',
        'offset_days',
        'source_evaluation_id',
        'target_date',
        'scheduled_for',
        'schedule_id',
        'status',
        'failure_code',
        'cancel_reason',
        'attempts',
        'claimed_at',
        'claim_token',
    ];

    protected function casts(): array
    {
        return [
            'milestone'     => PostOpMilestone::class,
            'status'        => PostOpReturnStatus::class,
            'target_date'   => 'date',
            'scheduled_for' => 'datetime',
            'claimed_at'    => 'datetime',
            'offset_days'   => 'integer',
            'attempts'      => 'integer',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
