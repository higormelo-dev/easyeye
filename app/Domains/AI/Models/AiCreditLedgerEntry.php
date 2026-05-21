<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiLedgerEntryType;
use App\Models\Entity;
use App\Models\Subscription;
use App\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreditLedgerEntry extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_credit_ledger_entries';

    protected $fillable = [
        'entity_id',
        'wallet_id',
        'subscription_id',
        'ai_run_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'metadata',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type'          => AiLedgerEntryType::class,
            'amount'        => 'integer',
            'balance_after' => 'integer',
            'metadata'      => 'array',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AiCreditWallet::class, 'wallet_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): \Database\Factories\AI\AiCreditLedgerEntryFactory
    {
        return \Database\Factories\AI\AiCreditLedgerEntryFactory::new();
    }
}
