<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

use Carbon\{CarbonImmutable, CarbonInterface};

/** Unidade do intervalo de uma tarefa de retorno. */
enum IntervalUnit: string
{
    case Days   = 'days';
    case Weeks  = 'weeks';
    case Months = 'months';

    /** Maior valor aceito por unidade (≈ 10 anos): trava intervalo absurdo no conteúdo. */
    public function maxValue(): int
    {
        return match ($this) {
            self::Days   => 3650,
            self::Weeks  => 520,
            self::Months => 120,
        };
    }

    /**
     * `$from` + `$amount` desta unidade. Mês SEM overflow (31/01 + 1 mês = 28/02 ou
     * 29/02, nunca 03/03) — vencimento que pula de mês é o tipo de erro que
     * ninguém percebe até a paciente reclamar.
     */
    public function addTo(CarbonInterface $from, int $amount): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from)->startOfDay();

        return match ($this) {
            self::Days   => $date->addDays($amount),
            self::Weeks  => $date->addWeeks($amount),
            self::Months => $date->addMonthsNoOverflow($amount),
        };
    }

    /** Plural: "meses". */
    public function label(): string
    {
        return __('recall_protocol.units.' . $this->value);
    }

    /** Singular: "mês" (para "1 mês"). */
    public function labelOne(): string
    {
        return __('recall_protocol.units_one.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $unit): string => $unit->value, self::cases());
    }
}
