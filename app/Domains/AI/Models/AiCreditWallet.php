<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Models\Entity;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCreditWallet extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_credit_wallets';

    protected $fillable = [
        'entity_id',
        'balance',
        'reserved_balance',
        'lifetime_purchased',
        'lifetime_consumed',
    ];

    protected function casts(): array
    {
        return [
            'balance'            => 'integer',
            'reserved_balance'   => 'integer',
            'lifetime_purchased' => 'integer',
            'lifetime_consumed'  => 'integer',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(AiCreditLedgerEntry::class, 'wallet_id');
    }

    protected static function newFactory(): \Database\Factories\AI\AiCreditWalletFactory
    {
        return \Database\Factories\AI\AiCreditWalletFactory::new();
    }
}
