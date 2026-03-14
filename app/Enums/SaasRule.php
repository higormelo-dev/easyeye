<?php

declare(strict_types=1);

namespace App\Enums;

enum SaasRule: string
{
    case Admin     = 'admin';
    case Financial = 'financial';
    case Support   = 'support';
    case User      = 'user';

    /**
     * Human-readable label (pt-BR).
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin     => 'Administrador',
            self::Financial => 'Financeiro',
            self::Support   => 'Suporte',
            self::User      => 'Usuário Comum',
        };
    }

    /**
     * All valid string values for this context.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Key-value map suitable for select inputs: ['admin' => 'Administrador', ...].
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
