<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participante de uma regra percentual (E4): o executor (médico do item,
 * doctor_id nulo) ou um médico fixo, com % do valor do grupo. Gravado só
 * pelo DoctorPayoutRuleService (soma 100%, um executor, médicos da clínica).
 */
class DoctorPayoutRuleParticipant extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const ROLE_EXECUTOR = 'executor';

    public const ROLE_DOCTOR = 'doctor';

    protected $fillable = [
        'entity_id',
        'doctor_payout_rule_id',
        'role',
        'doctor_id',
        'percentage',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DoctorPayoutRule::class, 'doctor_payout_rule_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
