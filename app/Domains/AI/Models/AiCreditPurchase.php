<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiCreditPurchaseStatus;
use App\Models\{Entity, Subscription, User};
use App\Traits\Auditable;
use Database\Factories\AI\AiCreditPurchaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreditPurchase extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_credit_purchases';

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'requested_by',
        'credited_ledger_entry_id',
        'package_code',
        'credits',
        'amount_cents',
        'currency',
        'status',
        'description',
        'metadata',
        'idempotency_key',
        'credited_at',
        'cancelled_at',
        'failed_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'credits'      => 'integer',
            'amount_cents' => 'integer',
            'status'       => AiCreditPurchaseStatus::class,
            'metadata'     => 'array',
            'credited_at'  => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at'    => 'datetime',
            'refunded_at'  => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function creditedLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(AiCreditLedgerEntry::class, 'credited_ledger_entry_id');
    }

    protected static function newFactory(): AiCreditPurchaseFactory
    {
        return AiCreditPurchaseFactory::new();
    }
}
