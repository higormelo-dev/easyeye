<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\PartnerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Partner extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'document',
        'type',
        'commission_rate',
        'token',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type'            => PartnerType::class,
            'commission_rate' => 'decimal:2',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Clínicas atribuídas a este parceiro */
    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class, 'partner_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(PartnerLead::class, 'partner_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class, 'partner_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Total de comissões pendentes de pagamento (R$) */
    public function pendingCommissionsAmount(): float
    {
        return (float) $this->commissions()
            ->where('status', \App\Enums\CommissionStatus::Pending->value)
            ->sum('amount');
    }
}
