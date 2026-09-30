<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PaymentMethodCast;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Pagamento (parcial ou total) de um fechamento de repasse — ver doc da
 * migration `create_doctor_payout_payments_table`. Escrito só pelo
 * DoctorPayoutClosingService (FOR UPDATE do fechamento, soma ≤ total).
 * Quem registrou = created_by; estorno lógico em reversed_*.
 */
class DoctorPayoutPayment extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'doctor_payout_id',
        'amount',
        'paid_at',
        'payment_method',
        'notes',
        'cash_entry_id',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'paid_at'        => 'date',
            'payment_method' => PaymentMethodCast::class,
            'reversed_at'    => 'datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(DoctorPayout::class, 'doctor_payout_id');
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialCashEntry::class, 'cash_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Rota só resolve pagamento da clínica da sessão; id que não é UUID = 404. */
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
