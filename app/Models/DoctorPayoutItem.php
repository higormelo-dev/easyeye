<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutBasis, DoctorPayoutBeneficiaryRole, DoctorPayoutCalculation, DoctorPayoutServiceType, DoctorPayoutSourceType};
use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retrato de um ato incluído num fechamento — ver doc da migration
 * `create_doctor_payout_items_table`.
 *
 * Gravado em lote (query builder) pelo DoctorPayoutClosingService; sem
 * Auditable por linha — a auditoria fica no DoctorPayout. O model serve à
 * leitura (demonstrativo, apuração, exportação).
 */
class DoctorPayoutItem extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'doctor_payout_id',
        'doctor_id',
        'beneficiary_role',
        'source_type',
        'source_id',
        'service_type',
        'performed_at',
        'patient_id',
        'covenant_id',
        'is_particular',
        'covenant_name',
        'description',
        'visit_type_id',
        'procedure_id',
        'exam_type_id',
        'base_amount',
        'base_source',
        'basis',
        'tranche',
        'received_amount',
        'expected_amount',
        'released_before_amount',
        'receipts_until',
        'receipts',
        'doctor_payout_rule_id',
        'rule_calculation',
        'rule_percentage',
        'rule_fixed_amount',
        'share_percentage',
        'net_amount',
        'deductions_amount',
        'split',
        'payout_amount',
        'warnings',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'source_type'            => DoctorPayoutSourceType::class,
            'service_type'           => DoctorPayoutServiceType::class,
            'performed_at'           => 'datetime',
            'is_particular'          => 'boolean',
            'base_amount'            => 'decimal:2',
            'base_source'            => DoctorPayoutBaseSource::class,
            'basis'                  => DoctorPayoutBasis::class,
            'tranche'                => 'integer',
            'received_amount'        => 'decimal:2',
            'expected_amount'        => 'decimal:2',
            'released_before_amount' => 'decimal:2',
            'receipts_until'         => 'date',
            'receipts'               => 'array',
            'beneficiary_role'       => DoctorPayoutBeneficiaryRole::class,
            'share_percentage'       => 'decimal:2',
            'net_amount'             => 'decimal:2',
            'deductions_amount'      => 'decimal:2',
            'split'                  => 'array',
            'rule_calculation'       => DoctorPayoutCalculation::class,
            'rule_percentage'        => 'decimal:2',
            'rule_fixed_amount'      => 'decimal:2',
            'payout_amount'          => 'decimal:2',
            'warnings'               => 'array',
            'voided_at'              => 'datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(DoctorPayout::class, 'doctor_payout_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class)->withTrashed();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DoctorPayoutRule::class, 'doctor_payout_rule_id')->withTrashed();
    }
}
