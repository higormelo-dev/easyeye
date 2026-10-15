<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Caráter do atendimento (TISS: 1 eletivo, 2 urgência/emergência).
 */
enum SurgeryUrgency: string
{
    case Elective = 'elective';
    case Urgent   = 'urgent';

    public function label(): string
    {
        return __('surgery_enums.surgery_urgency.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> valor => rótulo no idioma atual */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function tissCode(): string
    {
        return $this === self::Urgent ? '2' : '1';
    }
}
