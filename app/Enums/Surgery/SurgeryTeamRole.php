<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Papel de um membro da equipe cirúrgica.
 */
enum SurgeryTeamRole: string
{
    case Surgeon          = 'surgeon';
    case Assistant        = 'assistant';
    case Anesthesiologist = 'anesthesiologist';
    case ScrubNurse       = 'scrub_nurse';
    case CirculatingNurse = 'circulating_nurse';
    case Technician       = 'technician';

    public function label(): string
    {
        return __('surgery_enums.surgery_team_role.' . $this->value);
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
}
