<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\PartnerLeadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerLead extends Model
{
    use HasUuids;

    protected $fillable = [
        'partner_id',
        'entity_id',
        'name',
        'email',
        'phone',
        'city',
        'state',
        'status',
        'notes',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => PartnerLeadStatus::class,
            'converted_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
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

    public function hasConverted(): bool
    {
        return $this->status === PartnerLeadStatus::Converted;
    }
}
