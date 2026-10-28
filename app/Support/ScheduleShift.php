<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\{Carbon, CarbonInterface};

/**
 * Turnos da agenda — regra ÚNICA (filtro `bout` da Agenda, Dashboard, teto de
 * encaixes e impressão): manhã antes das 13h, tarde das 13h às 18h, noite a
 * partir das 18h. `bout` da URL da Agenda: 1 = todos, 2 = manhã, 3 = tarde,
 * 4 = noite.
 */
final class ScheduleShift
{
    public const MORNING = 'morning';

    public const AFTERNOON = 'afternoon';

    public const EVENING = 'evening';

    public const ALL = [self::MORNING, self::AFTERNOON, self::EVENING];

    /** Hora em que cada turno começa (o seguinte termina nela). */
    private const STARTS = [self::MORNING => 0, self::AFTERNOON => 13, self::EVENING => 18];

    private const BOUTS = [2 => self::MORNING, 3 => self::AFTERNOON, 4 => self::EVENING];

    public static function of(int $hour): string
    {
        return match (true) {
            $hour < self::STARTS[self::AFTERNOON] => self::MORNING,
            $hour < self::STARTS[self::EVENING]   => self::AFTERNOON,
            default                               => self::EVENING,
        };
    }

    public static function ofTime(CarbonInterface $at): string
    {
        return self::of($at->hour);
    }

    /** `bout` da Agenda → turno; 1 (ou desconhecido) = todos (null). */
    public static function fromBout(int $bout): ?string
    {
        return self::BOUTS[$bout] ?? null;
    }

    /**
     * Início (inclusive) e fim (exclusivo) do turno no dia.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function bounds(CarbonInterface $day, string $shift): array
    {
        $start = Carbon::parse($day->toDateString())->setTime(self::STARTS[$shift], 0);
        $end   = match ($shift) {
            self::MORNING   => Carbon::parse($day->toDateString())->setTime(self::STARTS[self::AFTERNOON], 0),
            self::AFTERNOON => Carbon::parse($day->toDateString())->setTime(self::STARTS[self::EVENING], 0),
            default         => Carbon::parse($day->toDateString())->addDay()->startOfDay(),
        };

        return [$start, $end];
    }

    public static function label(string $shift): string
    {
        return __('schedules.shift_' . $shift);
    }
}
