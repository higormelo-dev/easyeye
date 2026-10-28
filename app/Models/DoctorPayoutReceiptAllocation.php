<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoctorPayout\DoctorPayoutSourceType;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Recebimento manual alocado a um ato de repasse — ver doc da migration
 * `create_doctor_payout_receipt_allocations_table`. Escrito só pelo
 * DoctorPayoutReceiptAllocationService (lock da receita, soma ≤ valor).
 */
class DoctorPayoutReceiptAllocation extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'cash_entry_id',
        'source_type',
        'source_id',
        'amount',
        'notes',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => DoctorPayoutSourceType::class,
            'amount'      => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialCashEntry::class, 'cash_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Rota só resolve alocação da clínica da sessão; id que não é UUID = 404. */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $entityId = session('selected_entity_id');

        abort_unless($entityId, 403);

        $field ??= $this->getRouteKeyName();

        abort_if($field === $this->getKeyName() && ! Str::isUuid((string) $value), 404);

        return static::query()
            ->where($field, $value)
            ->where('entity_id', $entityId)
            ->firstOrFail();
    }
}
