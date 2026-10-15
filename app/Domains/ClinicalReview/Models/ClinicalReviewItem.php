<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Models;

use App\Domains\ClinicalReview\Enums\ReviewDecision;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma versão dentro de um convite, com a decisão do Revisor (gravada UMA vez, no envio do
 * parecer — o trigger do banco recusa mudar depois).
 *
 * @property string          $id
 * @property string          $invitation_id
 * @property string          $subject_id
 * @property string          $content_hash
 * @property ?ReviewDecision $decision
 */
class ClinicalReviewItem extends Model
{
    use HasUuids;

    public const SUBJECT_PROTOCOL_VERSION = 'recall_protocol_version';

    protected $fillable = ['invitation_id', 'subject_type', 'subject_id', 'subject_key', 'subject_version', 'content_hash'];

    protected function casts(): array
    {
        return [
            'decision'        => ReviewDecision::class,
            'decided_at'      => 'datetime',
            'subject_version' => 'integer',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ClinicalReviewInvitation::class, 'invitation_id');
    }

    public function isDecided(): bool
    {
        return $this->decision !== null;
    }
}
