<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Enums;

/**
 * Situação de um convite de revisão (clinical_review_invitations.status).
 *
 *   pending ──envio do parecer──▶ submitted ──aceite──▶ accepted
 *      │                              └──────recusa──▶ refused
 *      ├──prazo──▶ expired
 *      └──Manager──▶ revoked
 *
 * `accepted`, `refused`, `expired` e `revoked` são finais: um novo parecer exige um novo
 * convite (o link antigo nunca "reabre").
 */
enum ReviewInvitationStatus: string
{
    case Pending   = 'pending';
    case Submitted = 'submitted';
    case Accepted  = 'accepted';
    case Refused   = 'refused';
    case Expired   = 'expired';
    case Revoked   = 'revoked';

    /** @return array<string, array<int, self>> */
    public static function transitions(): array
    {
        return [
            self::Pending->value   => [self::Submitted, self::Expired, self::Revoked],
            self::Submitted->value => [self::Accepted, self::Refused],
            self::Accepted->value  => [],
            self::Refused->value   => [],
            self::Expired->value   => [],
            self::Revoked->value   => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value], true);
    }

    /** O Revisor ainda pode abrir e responder? */
    public function isOpenForReviewer(): bool
    {
        return $this === self::Pending;
    }

    /** O Revisor já enviou o parecer (a conferência da EasyEye pode estar pendente ou concluída)? */
    public function isAnswered(): bool
    {
        return in_array($this, [self::Submitted, self::Accepted, self::Refused], true);
    }

    /** Estado final (nada mais muda). */
    public function isFinal(): bool
    {
        return self::transitions()[$this->value] === [];
    }

    public function label(): string
    {
        return __('clinical_review.status.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
