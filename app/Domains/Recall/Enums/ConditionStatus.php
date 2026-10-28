<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/**
 * Situação de uma condição crônica do paciente (patient_conditions.status).
 *
 *   suggested ──médico confirma──▶ active ──alta──▶ discharged
 *       └──médico descarta──▶ rejected
 *
 * `suggested` é o que o sistema DETECTOU e ninguém confirmou (CID genérico, medicação sem confirmação):
 * não gera retorno nem convite. Só `active` dispara o protocolo.
 */
enum ConditionStatus: string
{
    case Suggested  = 'suggested';
    case Active     = 'active';
    case Discharged = 'discharged';
    case Rejected   = 'rejected';

    /** Ainda "em aberto" (índice único parcial: uma só por paciente+protocolo+olho). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Suggested, self::Active], true);
    }

    public function label(): string
    {
        return __('recall.condition_status.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
