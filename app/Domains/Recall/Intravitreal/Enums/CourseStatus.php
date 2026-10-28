<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Enums;

/** Situação do curso de injeções intravítreas de um olho. */
enum CourseStatus: string
{
    case Active       = 'active';
    case Paused       = 'paused';
    case Completed    = 'completed';
    case Discontinued = 'discontinued';
    case Lost         = 'lost';

    /** O curso ainda está em andamento (ocupa o olho: só um por olho). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Active, self::Paused], true);
    }

    public function label(): string
    {
        return __('recall_course.status.' . $this->value);
    }
}
