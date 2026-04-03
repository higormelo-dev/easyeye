<?php

namespace App\Traits;

use Carbon\Carbon;

trait HasBusinessDays
{
    private function willExpireInOneBusinessDay(Carbon $expiresAt): bool
    {
        return $this->countBusinessDays(Carbon::now(), $expiresAt) <= 1;
    }

    private function countBusinessDays(Carbon $start, Carbon $end): int
    {
        $days    = 0;
        $current = $start->copy()->addDay();

        while ($current <= $end) {
            if ($this->isBusinessDay($current)) {
                $days++;
            }
            $current->addDay();
        }

        return $days;
    }

    private function isBusinessDay(Carbon $date): bool
    {
        return !$date->isWeekend() && !$this->isHoliday($date);
    }

    private function isHoliday(Carbon $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->getHolidays($date->year));
    }

    private function getHolidays(int $year): array
    {
        $easter = $this->getEasterDate($year);

        return [
            "{$year}-01-01",
            "{$year}-04-21",
            "{$year}-05-01",
            "{$year}-09-07",
            "{$year}-10-12",
            "{$year}-11-02",
            "{$year}-11-15",
            "{$year}-11-20",
            "{$year}-12-25",
            $easter->copy()->subDays(48)->format('Y-m-d'),
            $easter->copy()->subDays(47)->format('Y-m-d'),
            $easter->copy()->subDays(46)->format('Y-m-d'),
            $easter->copy()->subDays(2)->format('Y-m-d'),
            $easter->format('Y-m-d'),
            $easter->copy()->addDays(60)->format('Y-m-d'),
        ];
    }

    private function getEasterDate(int $year): Carbon
    {
        return Carbon::createFromDate($year, 3, 21)->addDays(easter_days($year));
    }
}
