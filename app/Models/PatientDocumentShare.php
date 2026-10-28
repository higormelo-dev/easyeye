<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

/**
 * Grant de visibilidade de um documento clínico (laudo/exame/anexo) pro
 * titular no Portal do Paciente. Ver App\Services\PatientDocumentAccessService
 * — ÚNICO ponto que decide se um paciente pode LER um `shareable` (lição da
 * auditoria de 38 IDOR desta sessão: checagem de posse duplicada por
 * controller é o padrão-raiz de quase todos os achados).
 *
 * Sem HasAuditColumns: a tabela não tem created_by/updated_by — granted_by e
 * revoked_by já identificam o ator de cada ação com mais precisão que os
 * genéricos.
 */
class PatientDocumentShare extends Model
{
    use Auditable;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'patient_id',
        'shareable_type',
        'shareable_id',
        'granted_by',
        'granted_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(EntityUser $by, ?string $reason = null): void
    {
        $this->forceFill([
            'revoked_by'        => $by->getKey(),
            'revoked_at'        => now(),
            'revocation_reason' => $reason,
        ])->save();
    }
}
