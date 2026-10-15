<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Fase do checklist de cirurgia segura (OMS): Entrada antes da anestesia,
 * Pausa Cirúrgica antes da incisão, Saída antes de o paciente deixar a sala.
 */
enum ChecklistPhase: string
{
    case SignIn  = 'sign_in';
    case TimeOut = 'time_out';
    case SignOut = 'sign_out';

    public function label(): string
    {
        return __('surgery_schedule.checklist.phases.' . $this->value);
    }

    /** Orientação curta de quando a fase acontece. */
    public function when(): string
    {
        return __('surgery_schedule.checklist.phase_when.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
