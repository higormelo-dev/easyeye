<?php

declare(strict_types=1);

namespace App\Domains\Recall\Exceptions;

use RuntimeException;

/**
 * Ação da equipe sobre um retorno recusada por regra de NEGÓCIO (a linha mudou de estado, a regra está
 * suspensa, a data não faz sentido). `reason()` é o código que a tela traduz; a mensagem é para o log.
 */
final class RecallActionException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const NOT_WORKABLE = 'not_workable';

    public const RULE_SUSPENDED = 'rule_suspended';

    public const NOT_REOPENABLE = 'not_reopenable';

    public const INVALID_DATE = 'invalid_date';

    public const REASON_REQUIRED = 'reason_required';

    public const LINK_MISMATCH = 'link_mismatch';

    public const NOT_FOR_COURSE = 'not_for_course';

    private function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Retorno não encontrado nesta clínica.');
    }

    public static function notWorkable(string $lifecycle): self
    {
        return new self(self::NOT_WORKABLE, "O retorno não está mais em aberto (situação: {$lifecycle}).");
    }

    public static function ruleSuspended(): self
    {
        return new self(self::RULE_SUSPENDED, 'A regra deste retorno está suspensa: nenhuma ação enquanto durar a suspensão.');
    }

    public static function notReopenable(string $lifecycle): self
    {
        return new self(self::NOT_REOPENABLE, "Só retorno adiado ou dispensado pode ser reaberto (situação: {$lifecycle}).");
    }

    /** "Feito fora" não vale para a aplicação de um curso: o intervalo seguinte é decisão do médico, não da recepção. */
    public static function notForCourse(): self
    {
        return new self(self::NOT_FOR_COURSE, 'Aplicação do tratamento não se marca como feita em outro serviço: o médico decide o novo intervalo.');
    }

    public static function invalidDate(string $detail): self
    {
        return new self(self::INVALID_DATE, $detail);
    }

    public static function reasonRequired(): self
    {
        return new self(self::REASON_REQUIRED, 'Informe o motivo.');
    }

    public static function linkMismatch(): self
    {
        return new self(self::LINK_MISMATCH, 'O agendamento não é do mesmo paciente (ou de outra clínica) deste retorno.');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
