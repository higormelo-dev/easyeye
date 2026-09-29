<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PaymentMethodCast;
use App\Concerns\HasEntityCode;
use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Str;

/**
 * Fechamento de repasse de um médico num período — ver doc da migration
 * `create_doctor_payouts_table`.
 *
 * Sem SoftDeletes de propósito: reabrir = status cancelled. Totais e status
 * são escritos só pelo DoctorPayoutClosingService.
 */
class DoctorPayout extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUuids;

    protected string $codePrefix = 'REP';

    protected string $codePrefixGlobal = 'REPP';

    protected $fillable = [
        'entity_id',
        'doctor_id',
        'code',
        'doctor_name',
        'doctor_record',
        'period_start',
        'period_end',
        'status',
        'items_count',
        'gross_amount',
        'items_amount',
        'adjustments_amount',
        'total_amount',
        'closed_at',
        'closed_by',
        'paid_at',
        'paid_amount',
        'payment_method',
        'payment_notes',
        'paid_by',
        'cash_entry_id',
        'payment_reversal_reason',
        'payment_reversed_by',
        'payment_reversed_at',
        'cancel_reason',
        'cancelled_by',
        'cancelled_at',
        'notes',
    ];

    /**
     * Default em PHP, não só no banco (mesma lição de PurchaseOrder::$attributes):
     * sem isso o objeto recém-criado fica sem status/totais até um fresh().
     */
    protected $attributes = [
        'status'             => 'closed',
        'items_count'        => 0,
        'gross_amount'       => 0,
        'items_amount'       => 0,
        'adjustments_amount' => 0,
        'total_amount'       => 0,
    ];

    protected function casts(): array
    {
        return [
            'status'              => DoctorPayoutStatus::class,
            'period_start'        => 'date',
            'period_end'          => 'date',
            'items_count'         => 'integer',
            'gross_amount'        => 'decimal:2',
            'items_amount'        => 'decimal:2',
            'adjustments_amount'  => 'decimal:2',
            'total_amount'        => 'decimal:2',
            'closed_at'           => 'datetime',
            'paid_at'             => 'date',
            'paid_amount'         => 'decimal:2',
            'payment_method'      => PaymentMethodCast::class,
            'payment_reversed_at' => 'datetime',
            'cancelled_at'        => 'datetime',
        ];
    }

    /**
     * {payout} só resolve fechamento da clínica da sessão: o de outra clínica
     * vira 404 antes de qualquer validação (os controllers ainda conferem).
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

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(DoctorPayoutItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(DoctorPayoutAdjustment::class);
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialCashEntry::class, 'cash_entry_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function paymentReversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_reversed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === DoctorPayoutStatus::Closed;
    }

    public function isPaid(): bool
    {
        return $this->status === DoctorPayoutStatus::Paid;
    }

    public function isCancelled(): bool
    {
        return $this->status === DoctorPayoutStatus::Cancelled;
    }
}
