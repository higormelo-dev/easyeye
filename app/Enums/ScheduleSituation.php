<?php

namespace App\Enums;

enum ScheduleSituation: int
{
    case Scheduled  = 1; // Agendado
    case Confirmed  = 2; // Confirmado
    case Waiting    = 3; // Aguardando (chegou)
    case Dilating   = 4; // Dilatando
    case Exam       = 5; // Em exame
    case InProgress = 6; // Em consulta
    case Attended   = 7; // Atendido
    case NoShow     = 8; // Faltou
    case Cancelled  = 9; // Cancelado

    public function label(): string
    {
        return match ($this) {
            self::Scheduled  => __('actions.situation_scheduled'),
            self::Confirmed  => __('actions.situation_confirmed'),
            self::Waiting    => __('actions.situation_waiting'),
            self::Dilating   => __('actions.situation_dilating'),
            self::Exam       => __('actions.situation_exam'),
            self::InProgress => __('actions.situation_inprogress'),
            self::Attended   => __('actions.situation_attended'),
            self::NoShow     => __('actions.situation_noshow'),
            self::Cancelled  => __('actions.situation_cancelled'),
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Scheduled  => 'bg-secondary',
            self::Confirmed  => 'bg-info text-dark',
            self::Waiting    => 'bg-warning text-dark',
            self::Dilating   => 'bg-purple text-white',
            self::Exam       => 'bg-orange text-white',
            self::InProgress => 'bg-primary',
            self::Attended   => 'bg-success',
            self::NoShow     => 'bg-danger',
            self::Cancelled  => 'bg-dark',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Scheduled  => 'fa-calendar',
            self::Confirmed  => 'fa-check-circle',
            self::Waiting    => 'fa-clock',
            self::Dilating   => 'fa-eye-dropper',
            self::Exam       => 'fa-stethoscope',
            self::InProgress => 'fa-user-md',
            self::Attended   => 'fa-check-double',
            self::NoShow     => 'fa-user-times',
            self::Cancelled  => 'fa-ban',
        };
    }

    /**
     * Situações para as quais é permitido transitar a partir desta.
     *
     * @return array<int>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled  => [self::Confirmed->value, self::Waiting->value, self::NoShow->value, self::Cancelled->value],
            self::Confirmed  => [self::Waiting->value, self::NoShow->value, self::Cancelled->value],
            self::Waiting    => [self::Dilating->value, self::InProgress->value, self::Cancelled->value],
            self::Dilating   => [self::Exam->value, self::InProgress->value, self::Cancelled->value],
            self::Exam       => [self::InProgress->value, self::Cancelled->value],
            self::InProgress => [self::Attended->value, self::Cancelled->value],
            self::Attended   => [],
            self::NoShow     => [],
            self::Cancelled  => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Attended, self::NoShow, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    public function circleClass(): string
    {
        return match ($this) {
            self::Scheduled  => 'text-secondary',
            self::Confirmed  => 'text-info',
            self::Waiting    => 'text-warning',
            self::Dilating   => 'text-purple',
            self::Exam       => 'text-orange',
            self::InProgress => 'text-primary',
            self::Attended   => 'text-success',
            self::NoShow     => 'text-danger',
            self::Cancelled  => 'text-dark',
        };
    }
}
