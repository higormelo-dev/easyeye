<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Models;

use App\Domains\ClinicalReview\Enums\ReviewInvitationStatus;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

/**
 * Convite pessoal e temporário para o Revisor revisar um CONJUNTO de versões de protocolo.
 * O token nunca é gravado (só o sha256); o PIN opcional só como hash; o rascunho do parecer
 * fica cifrado e é apagado ao enviar, expirar ou revogar.
 *
 * @property string                 $id
 * @property string                 $reviewer_id
 * @property ReviewInvitationStatus $status
 * @property string                 $set_hash
 */
class ClinicalReviewInvitation extends Model
{
    use HasAuditColumns;
    use HasUuids;

    /** Tentativas erradas de PIN antes de travar (e por quantos minutos). */
    public const MAX_ACCESS_ATTEMPTS = 5;

    public const ACCESS_LOCK_MINUTES = 15;

    protected $fillable = [
        'reviewer_id', 'reviewer_name', 'reviewer_email', 'token_hash', 'set_hash', 'status', 'expires_at',
        'access_code_hash',
    ];

    protected $hidden = ['token_hash', 'access_code_hash', 'draft_payload'];

    protected function casts(): array
    {
        return [
            'status'              => ReviewInvitationStatus::class,
            'expires_at'          => 'datetime',
            'sent_at'             => 'datetime',
            'last_opened_at'      => 'datetime',
            'access_locked_until' => 'datetime',
            'draft_saved_at'      => 'datetime',
            'submitted_at'        => 'datetime',
            'revoked_at'          => 'datetime',
            'open_count'          => 'integer',
            'access_attempts'     => 'integer',
            'draft_payload'       => 'encrypted:array',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(ClinicalReviewer::class, 'reviewer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClinicalReviewItem::class, 'invitation_id')->orderBy('subject_key')->orderBy('subject_version');
    }

    public function attestation(): HasOne
    {
        return $this->hasOne(ClinicalReviewAttestation::class, 'invitation_id');
    }

    public function acceptance(): HasOne
    {
        return $this->hasOne(ClinicalReviewAcceptance::class, 'invitation_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** O Revisor pode abrir e responder agora? (pendente e dentro do prazo) */
    public function isOpenForReviewer(): bool
    {
        return $this->status->isOpenForReviewer() && ! $this->isExpired();
    }

    /** O parecer já foi enviado: o link só leva à tela de conclusão. */
    public function isAnswered(): bool
    {
        return $this->status->isAnswered();
    }

    public function requiresAccessCode(): bool
    {
        return $this->access_code_hash !== null;
    }

    public function isAccessLocked(): bool
    {
        return $this->access_locked_until !== null && $this->access_locked_until->isFuture();
    }

    public function scopeStatus(Builder $query, ReviewInvitationStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(static fn (ReviewInvitationStatus $status): string => $status->value, $statuses));
    }
}
