<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Models;

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * O aceite da EasyEye: alguém conferiu (ou não) o CRM/UF e o RQE do Revisor no portal do
 * CFM. APPEND-ONLY. Só uma versão com aceite `accepted` pode ser liberada (invariante I1).
 */
class ClinicalReviewAcceptance extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const METHOD_CFM_PORTAL = 'cfm_portal';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('clinical_review_acceptances é append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('clinical_review_acceptances é append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'decision'    => AcceptanceDecision::class,
            'crm_checked' => 'boolean',
            'rqe_checked' => 'boolean',
            'checked_at'  => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ClinicalReviewInvitation::class, 'invitation_id');
    }
}
