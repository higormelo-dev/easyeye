<?php

namespace App\Enums;

enum PatientMood: int
{
    case Happy   = 1; // Tranquilo
    case Anxious = 2; // Ansioso
    case Nervous = 3; // Agitado
    case Angry   = 4; // Irritado

    public function label(): string
    {
        return match ($this) {
            self::Happy   => __('actions.mood_happy'),
            self::Anxious => __('actions.mood_anxious'),
            self::Nervous => __('actions.mood_nervous'),
            self::Angry   => __('actions.mood_angry'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Happy   => 'fa-smile',
            self::Anxious => 'fa-meh',
            self::Nervous => 'fa-frown',
            self::Angry   => 'fa-angry',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Happy   => 'bg-success',
            self::Anxious => 'bg-warning text-dark',
            self::Nervous => 'bg-orange text-white',
            self::Angry   => 'bg-danger',
        };
    }

    public function textClass(): string
    {
        return match ($this) {
            self::Happy   => 'text-success',
            self::Anxious => 'text-warning',
            self::Nervous => 'text-orange',
            self::Angry   => 'text-danger',
        };
    }
}
