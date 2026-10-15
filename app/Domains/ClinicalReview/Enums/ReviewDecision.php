<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Enums;

/** A decisão do Revisor sobre UMA versão do convite. */
enum ReviewDecision: string
{
    case Approved         = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected         = 'rejected';

    /** Só "aprovado" dispensa justificativa; pedir ajuste ou rejeitar exige dizer o porquê. */
    public function requiresComment(): bool
    {
        return $this !== self::Approved;
    }

    public function label(): string
    {
        return __('clinical_review.decision.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $decision): string => $decision->value, self::cases());
    }
}
