<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\{LgpdRequestStatus, LgpdRequestType};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LgpdRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'patient_id',
        'requester_name',
        'requester_email',
        'requester_document',
        'request_type',
        'status',
        'description',
        'response',
        'rejection_reason',
        'requested_at',
        'deadline_at',
        'responded_at',
        'responded_by',
    ];

    protected function casts(): array
    {
        return [
            'request_type' => LgpdRequestType::class,
            'status'       => LgpdRequestStatus::class,
            'requested_at' => 'datetime',
            'deadline_at'  => 'datetime',
            'responded_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'responded_by');
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen()
            && $this->deadline_at !== null
            && $this->deadline_at->isPast();
    }

    public function daysUntilDeadline(): ?int
    {
        if ($this->deadline_at === null) {
            return null;
        }

        return (int) now()->diffInDays($this->deadline_at, false);
    }
}
