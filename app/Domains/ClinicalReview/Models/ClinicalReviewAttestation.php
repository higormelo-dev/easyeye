<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * O atestado do Revisor (1:1 com o convite): identidade DECLARADA, texto exato da
 * declaração, conflito de interesses, IP/navegador e o hash do envio. APPEND-ONLY: o
 * trigger do banco só deixa gravar `receipt_sent_at` depois; este model recusa antes.
 */
class ClinicalReviewAttestation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static function (self $attestation): void {
            $changed = array_keys($attestation->getDirty());

            if (array_diff($changed, ['receipt_sent_at']) !== []) {
                throw new LogicException('clinical_review_attestations é append-only: só receipt_sent_at muda.');
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('clinical_review_attestations é append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'conflict_of_interest'   => 'boolean',
            'allow_identity_display' => 'boolean',
            'signed_at'              => 'datetime',
            'receipt_sent_at'        => 'datetime',
            'created_at'             => 'datetime',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ClinicalReviewInvitation::class, 'invitation_id');
    }
}
