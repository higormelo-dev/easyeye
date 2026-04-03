<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCommission extends Model
{
    use HasUuids;

    protected $fillable = [
        'partner_id',
        'entity_id',
        'subscription_id',
        'amount',
        'rate',
        'period',
        'status',
        'due_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status'     => CommissionStatus::class,
            'amount'     => 'decimal:2',
            'rate'       => 'decimal:2',
            'due_at'     => 'datetime',
            'paid_at'    => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function isPending(): bool
    {
        return $this->status === CommissionStatus::Pending;
    }
}
