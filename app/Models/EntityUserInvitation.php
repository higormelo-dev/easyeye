<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite de uma clínica a um usuário (não médico) que já tem login no
 * EasyEye — ver UserInvitationService. user_id null = e-mail sem conta
 * elegível: o convite existe (resposta/lista iguais), mas ninguém aceita.
 */
class EntityUserInvitation extends Model
{
    use Auditable;
    use HasUuids;

    /**
     * Auditable: user_id fora da trilha — no log ele distinguiria convite a
     * e-mail com conta de convite inerte (anti-enumeração).
     *
     * @var list<string>
     */
    protected array $auditExclude = ['user_id'];

    protected $fillable = [
        'entity_id',
        'user_id',
        'invited_by',
        'email',
        'rule',
        'status',
        'expires_at',
        'notified_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'   => 'datetime',
            'notified_at'  => 'datetime',
            'responded_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === DoctorInvitation::STATUS_PENDING && $this->expires_at?->isFuture();
    }
}
