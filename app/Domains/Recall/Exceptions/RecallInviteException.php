<?php

declare(strict_types=1);

namespace App\Domains\Recall\Exceptions;

use App\Domains\Recall\Enums\InviteBlocker;
use RuntimeException;

/**
 * Convite de retorno por WhatsApp recusado por regra de NEGÓCIO. `reason()` é o código que a tela traduz;
 * `blockers()` traz o(s) motivo(s) de elegibilidade (InviteBlocker) quando foi isso.
 */
final class RecallInviteException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const BLOCKED = 'blocked';

    public const ALREADY_TODAY = 'already_today';

    /** @param list<InviteBlocker> $blockers */
    private function __construct(private readonly string $reason, string $message, private readonly array $blockers = [])
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Retorno não encontrado nesta clínica.');
    }

    /** @param list<InviteBlocker> $blockers */
    public static function blocked(array $blockers): self
    {
        return new self(
            self::BLOCKED,
            'Convite não permitido: ' . implode(', ', array_map(static fn (InviteBlocker $blocker): string => $blocker->value, $blockers)),
            $blockers,
        );
    }

    public static function alreadyToday(): self
    {
        return new self(self::ALREADY_TODAY, 'Este paciente já recebeu um convite hoje.');
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return list<InviteBlocker> */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
