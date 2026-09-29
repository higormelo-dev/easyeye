<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoctorPayout\{DoctorPayoutCalculation, DoctorPayoutPayerScope, DoctorPayoutServiceType};
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Regra de repasse médico — ver doc da migration `create_doctor_payout_rules_table`.
 *
 * Escrita só pelo DoctorPayoutRuleService, que valida o escopo, as referências
 * (globais ou da clínica) e a sobreposição de vigência. A resolução (qual regra
 * vale para cada item) fica no DoctorPayoutRuleResolver.
 */
class DoctorPayoutRule extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'doctor_id',
        'service_type',
        'visit_type_id',
        'procedure_id',
        'exam_type_id',
        'payer_scope',
        'covenant_id',
        'calculation',
        'percentage',
        'fixed_amount',
        'valid_from',
        'valid_until',
        'active',
        'notes',
    ];

    protected $attributes = [
        'payer_scope' => 'any',
        'active'      => true,
    ];

    protected function casts(): array
    {
        return [
            'service_type' => DoctorPayoutServiceType::class,
            'payer_scope'  => DoctorPayoutPayerScope::class,
            'calculation'  => DoctorPayoutCalculation::class,
            'percentage'   => 'decimal:2',
            'fixed_amount' => 'decimal:2',
            'valid_from'   => 'date',
            'valid_until'  => 'date',
            'active'       => 'boolean',
        ];
    }

    /**
     * {rule} só resolve regra da clínica da sessão: a de outra clínica vira
     * 404 antes de qualquer validação.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $entityId = session('selected_entity_id');

        abort_unless($entityId, 403);

        $field ??= $this->getRouteKeyName();

        // Id que não é UUID nunca chega ao PostgreSQL (22P02 → 500).
        abort_if($field === $this->getKeyName() && ! Str::isUuid((string) $value), 404);

        return static::query()
            ->where($field, $value)
            ->where('entity_id', $entityId)
            ->firstOrFail();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function visitType(): BelongsTo
    {
        return $this->belongsTo(VisitType::class)->withTrashed();
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class)->withTrashed();
    }

    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class)->withTrashed();
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class)->withTrashed();
    }
}
