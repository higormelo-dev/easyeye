<?php

declare(strict_types=1);

namespace App\Domains\Recall\Exceptions;

use RuntimeException;

/**
 * Ativação (adoção) de um protocolo pela clínica recusada por regra de NEGÓCIO. `reason()` é o código que a tela
 * traduz; `errors()` traz, para `invalid_parameters`, o campo → código de cada problema (intervalo fora da faixa,
 * tarefa obrigatória desligada...).
 */
final class ProtocolActivationException extends RuntimeException
{
    public const PROTOCOL_UNAVAILABLE = 'protocol_unavailable';

    public const VERSION_MISMATCH = 'version_mismatch';

    public const VERSION_NOT_RELEASED = 'version_not_released';

    public const NO_VALIDATION_EVIDENCE = 'no_validation_evidence';

    public const NOT_ACKNOWLEDGED = 'not_acknowledged';

    public const INVALID_PARAMETERS = 'invalid_parameters';

    public const NOT_ADOPTED = 'not_adopted';

    public const REASON_REQUIRED = 'reason_required';

    /** @param array<string, string> $errors */
    private function __construct(private readonly string $reason, string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public static function protocolUnavailable(): self
    {
        return new self(self::PROTOCOL_UNAVAILABLE, 'Protocolo inexistente ou arquivado.');
    }

    public static function versionMismatch(): self
    {
        return new self(self::VERSION_MISMATCH, 'A versão informada não é deste protocolo.');
    }

    public static function versionNotReleased(string $state): self
    {
        return new self(self::VERSION_NOT_RELEASED, "Só a versão liberada pode ser ativada (situação: {$state}).");
    }

    public static function noValidationEvidence(string $detail): self
    {
        return new self(self::NO_VALIDATION_EVIDENCE, "Sem prova de validação: {$detail}.");
    }

    public static function notAcknowledged(): self
    {
        return new self(self::NOT_ACKNOWLEDGED, 'É preciso confirmar que leu a regra e a validação.');
    }

    /** @param array<string, string> $errors */
    public static function invalidParameters(array $errors): self
    {
        return new self(self::INVALID_PARAMETERS, 'Parâmetros fora da faixa validada: ' . implode(', ', array_keys($errors)) . '.', $errors);
    }

    public static function notAdopted(): self
    {
        return new self(self::NOT_ADOPTED, 'A clínica não tem este protocolo ativo.');
    }

    public static function reasonRequired(): self
    {
        return new self(self::REASON_REQUIRED, 'Informe o motivo.');
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
