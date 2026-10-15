<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Adesão do paciente ao tratamento pós-operatório.
 */
enum PostOpAdherence: string
{
    case Good    = 'good';
    case Partial = 'partial';
    case Poor    = 'poor';

    public function label(): string
    {
        return __('surgery_postop.enums.post_op_adherence.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
