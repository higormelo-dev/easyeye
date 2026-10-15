<?php

declare(strict_types=1);

namespace App\Domains\Recall\Exceptions;

use RuntimeException;

/**
 * Ato do médico sobre uma condição crônica do paciente recusado por regra de NEGÓCIO (a condição mudou de
 * estado, o protocolo não está ativo na clínica, a data não faz sentido). `reason()` é o código que a tela
 * traduz; a mensagem é para o log.
 */
final class PatientConditionException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const PROTOCOL_NOT_ACTIVE = 'protocol_not_active';

    public const ALREADY_OPEN = 'already_open';

    public const NOT_OPEN = 'not_open';

    public const NOT_SUGGESTED = 'not_suggested';

    public const NOT_ACTIVE = 'not_active';

    public const INVALID_DATE = 'invalid_date';

    public const REASON_REQUIRED = 'reason_required';

    private function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Condição ou paciente não encontrado nesta clínica.');
    }

    public static function protocolNotActive(string $key): self
    {
        return new self(self::PROTOCOL_NOT_ACTIVE, "A clínica não tem o protocolo '{$key}' ativo (ou ele está parado): ative-o antes de registrar a condição.");
    }

    public static function alreadyOpen(string $key): self
    {
        return new self(self::ALREADY_OPEN, "Já existe uma condição '{$key}' em aberto para este olho.");
    }

    public static function notOpen(string $status): self
    {
        return new self(self::NOT_OPEN, "A condição não está mais em aberto (situação: {$status}).");
    }

    public static function notSuggested(string $status): self
    {
        return new self(self::NOT_SUGGESTED, "Só uma condição sugerida pode ser confirmada ou descartada (situação: {$status}).");
    }

    public static function notActive(string $status): self
    {
        return new self(self::NOT_ACTIVE, "Só uma condição ativa recebe alta (situação: {$status}).");
    }

    public static function invalidDate(string $detail): self
    {
        return new self(self::INVALID_DATE, $detail);
    }

    public static function reasonRequired(): self
    {
        return new self(self::REASON_REQUIRED, 'Informe o motivo da alta.');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
