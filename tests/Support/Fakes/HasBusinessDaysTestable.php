<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Traits\HasBusinessDays;
use Carbon\Carbon;

/**
 * Classe auxiliar que expõe os métodos privados do trait HasBusinessDays para teste.
 */
class HasBusinessDaysTestable
{
    use HasBusinessDays;

    public function publicCountBusinessDays(Carbon $start, Carbon $end): int
    {
        return $this->countBusinessDays($start, $end);
    }

    public function publicWillExpireInOneBusinessDay(Carbon $expiresAt): bool
    {
        return $this->willExpireInOneBusinessDay($expiresAt);
    }

    public function publicIsBusinessDay(Carbon $date): bool
    {
        return $this->isBusinessDay($date);
    }

    public function publicIsHoliday(Carbon $date): bool
    {
        return $this->isHoliday($date);
    }

    public function publicGetEasterDate(int $year): Carbon
    {
        return $this->getEasterDate($year);
    }
}
