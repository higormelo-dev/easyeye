<?php

declare(strict_types=1);

namespace App\Domains\Recall\Exceptions;

use App\Domains\Recall\Enums\ProtocolVersionState;
use RuntimeException;

/**
 * Operação recusada sobre uma versão de protocolo (estado errado, edição obsoleta,
 * transição inválida). A mensagem é para o log e para o teste; a tela traduz pelo
 * `reason()`.
 */
final class ProtocolVersionException extends RuntimeException
{
    public const NOT_DRAFT = 'not_draft';

    public const STALE = 'stale';

    public const INVALID_TRANSITION = 'invalid_transition';

    public const OPEN_VERSION_EXISTS = 'open_version_exists';

    public const FROZEN_CONTENT = 'frozen_content';

    private function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notDraft(ProtocolVersionState $state): self
    {
        return new self(self::NOT_DRAFT, "Só rascunho aceita edição (estado atual: {$state->value}).");
    }

    public static function stale(int $expected, int $actual): self
    {
        return new self(self::STALE, "Rascunho alterado por outra sessão (esperado lock {$expected}, atual {$actual}).");
    }

    public static function invalidTransition(ProtocolVersionState $from, ProtocolVersionState $to): self
    {
        return new self(self::INVALID_TRANSITION, "Transição de {$from->value} para {$to->value} não é permitida.");
    }

    public static function openVersionExists(string $protocolKey): self
    {
        return new self(self::OPEN_VERSION_EXISTS, "O protocolo {$protocolKey} já tem uma versão em andamento (rascunho ou em revisão).");
    }

    public static function frozenContent(string $column): self
    {
        return new self(self::FROZEN_CONTENT, "O campo {$column} de uma versão congelada não pode ser alterado.");
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
