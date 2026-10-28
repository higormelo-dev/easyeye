<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Evento do log de governança clínica: quem fez o quê com uma versão de protocolo
 * ou um convite de revisão, quando e por quê. APPEND-ONLY — o trigger do banco
 * recusa UPDATE/DELETE e este model recusa antes de chegar lá.
 */
class ClinicalGovernanceEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'subject_type', 'subject_id', 'event', 'from_state', 'to_state',
        'actor_user_id', 'actor_label', 'reason', 'meta',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('clinical_governance_events é append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('clinical_governance_events é append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }
}
