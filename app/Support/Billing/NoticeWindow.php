<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/**
 * Janela (horário comercial, no fuso do app) dos avisos do SaaS à clínica
 * pelo WhatsApp — a mesma das confirmações aos pacientes (08h–20h, ver
 * routes/console.php). Fora dela a mensagem espera o próximo início.
 */
final class NoticeWindow
{
    /** Agora, se dentro da janela; senão o próximo início dela. */
    public static function nextSendAt(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        [$start, $end] = self::bounds($now);

        if ($now->lessThan($start)) {
            return $start;
        }

        if ($now->greaterThanOrEqualTo($end)) {
            return $start->addDay();
        }

        return $now;
    }

    public static function isOpen(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return self::nextSendAt($now)->equalTo($now);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function bounds(CarbonImmutable $now): array
    {
        $start = self::time($now, (string) config('billing.notices.whatsapp_window.start', '08:00'), '08:00');
        $end   = self::time($now, (string) config('billing.notices.whatsapp_window.end', '20:00'), '20:00');

        return $end->greaterThan($start) ? [$start, $end] : [$now->startOfDay(), $now->endOfDay()];
    }

    private static function time(CarbonImmutable $day, string $value, string $fallback): CarbonImmutable
    {
        $value           = preg_match('/^\d{1,2}:\d{2}$/', $value) ? $value : $fallback;
        [$hour, $minute] = array_map('intval', explode(':', $value));

        return $day->setTime(min($hour, 23), min($minute, 59));
    }
}
