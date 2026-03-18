<?php

namespace App\Enums;

enum ScheduleSituation: int
{
    case Scheduled  = 1; // Agendado
    case Waiting    = 2; // Na sala de espera (chegou)
    case InProgress = 3; // Em atendimento
    case Attended   = 4; // Atendido
    case NoShow     = 5; // Faltou
    case Cancelled  = 6; // Cancelado

    public function label(): string
    {
        return match($this) {
            self::Scheduled  => 'Agendado',
            self::Waiting    => 'Aguardando',
            self::InProgress => 'Em atendimento',
            self::Attended   => 'Atendido',
            self::NoShow     => 'Faltou',
            self::Cancelled  => 'Cancelado',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::Scheduled  => 'bg-secondary',
            self::Waiting    => 'bg-warning text-dark',
            self::InProgress => 'bg-primary',
            self::Attended   => 'bg-success',
            self::NoShow     => 'bg-danger',
            self::Cancelled  => 'bg-dark',
        };
    }
}
