<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReferralEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'referral_code_id',
        'referred_entity_id',
        'event_type',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ReferralEventType::class,
            'created_at' => 'datetime',
        ];
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    public function referredEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'referred_entity_id');
    }
}
