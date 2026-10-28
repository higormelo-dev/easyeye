<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite de uma clínica a um médico que já tem login no EasyEye (ver
 * DoctorInvitationService). payload (dados digitados pela clínica) é
 * criptografado em repouso e descartado ao responder/cancelar.
 */
class DoctorInvitation extends Model
{
    use Auditable;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** Validade do convite (e do link assinado enviado por e-mail). */
    public const VALID_DAYS = 7;

    /** Convites encerrados (qualquer tipo) são apagados depois disto (LGPD). */
    public const RETENTION_DAYS = 90;

    /** Reenvio antes disto atualiza o convite, mas não manda outro e-mail. */
    public const RESEND_COOLDOWN_HOURS = 24;

    /**
     * Auditable: os dados digitados (mesmo cifrados) nunca vão para a trilha
     * de auditoria — descartá-los ao responder/cancelar precisa ser de fato.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['payload'];

    protected $fillable = [
        'entity_id',
        'user_id',
        'invited_by',
        'payload',
        'status',
        'expires_at',
        'notified_at',
        'responded_at',
    ];

    /** Nunca serializar os dados pessoais digitados pela clínica. */
    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload'      => 'encrypted:array',
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
        return $this->status === self::STATUS_PENDING && $this->expires_at?->isFuture();
    }
}
