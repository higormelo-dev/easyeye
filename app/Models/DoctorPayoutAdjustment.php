<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste manual (acréscimo > 0 / desconto < 0) de um fechamento de repasse —
 * ver doc da migration `create_doctor_payout_adjustments_table`. Escrito só
 * pelo DoctorPayoutClosingService, com o fechamento em `closed`.
 */
class DoctorPayoutAdjustment extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'doctor_payout_id',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(DoctorPayout::class, 'doctor_payout_id');
    }
}
