<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\{ConsentType, LegalBasis};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientConsent extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'patient_id',
        'consent_type',
        'legal_basis',
        'status',
        'document_version',
        'channel',
        'granted_by',
        'granted_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
        'ip_address',
        'user_agent',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'consent_type' => ConsentType::class,
            'legal_basis'  => LegalBasis::class,
            'granted_at'   => 'datetime',
            'revoked_at'   => 'datetime',
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

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'revoked_by');
    }

    public function isGranted(): bool
    {
        return $this->status === 'granted' && $this->revoked_at === null;
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }
}
