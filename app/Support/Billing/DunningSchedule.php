<?php

namespace App\Support\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Prazos da régua de cobrança (config `billing.dunning`), em dias corridos
 * contados a partir do dia do vencimento não pago:
 *
 *  - reminder_days_before: lembrete antes do vencimento (D-5);
 *  - soft_block_after_days: a partir deste dia de atraso, acesso limitado (D+3);
 *  - hard_block_after_days: a partir deste dia, sem acesso e assinatura
 *    encerrada (D+7).
 *
 * E a antecedência da renovação local (`billing.renewal_lead_days`), que
 * acompanha o lembrete: a cobrança sai antes dele (D-6).
 *
 * Mesma conta no PHP (Subscription::accessLevel) e no SQL
 * (Subscription::scopeAccessible): dias de atraso = dias entre o dia do
 * vencimento e hoje.
 */
final class DunningSchedule
{
    public static function reminderDaysBefore(): int
    {
        return max(0, (int) config('billing.dunning.reminder_days_before', 5));
    }

    /**
     * Antecedência da renovação local (cobrança emitida por nós, sem
     * recorrência no gateway): billing.renewal_lead_days, mas nunca menos que
     * um dia antes do lembrete — a cobrança (e o link) já existe quando o
     * lembrete do D-5 sai, com um dia de folga para nova tentativa se a
     * emissão falhar.
     */
    public static function renewalLeadDays(): int
    {
        return max(max(0, (int) config('billing.renewal_lead_days', 5)), self::reminderDaysBefore() + 1);
    }

    /**
     * Vencimentos até o fim deste dia já têm a cobrança da renovação local
     * emitida. Comparação por dia: a hora do agendador (01:00) não empurra
     * a emissão para o dia seguinte (o vencimento é às 23:59:59).
     */
    public static function renewalWindowEnd(?CarbonInterface $now = null): Carbon
    {
        return Carbon::instance($now ?? now())->addDays(self::renewalLeadDays())->endOfDay();
    }

    public static function softBlockAfterDays(): int
    {
        return max(0, (int) config('billing.dunning.soft_block_after_days', 3));
    }

    /** Nunca antes do bloqueio parcial. */
    public static function hardBlockAfterDays(): int
    {
        return max(self::softBlockAfterDays(), (int) config('billing.dunning.hard_block_after_days', 7));
    }

    /** Dias corridos entre o dia de `$since` e hoje (0 no próprio dia). */
    public static function daysSince(CarbonInterface $since, ?CarbonInterface $now = null): int
    {
        $now ??= now();

        return max(0, (int) round(Carbon::instance($since)->startOfDay()->diffInDays(Carbon::instance($now)->startOfDay(), false)));
    }

    /**
     * Atraso desde antes deste instante já chegou ao bloqueio total: quem tem
     * atraso desde `>= hardBlockCutoff()` ainda tem acesso (limitado ou não).
     */
    public static function hardBlockCutoff(?CarbonInterface $now = null): Carbon
    {
        $now ??= now();

        return Carbon::instance($now)->startOfDay()->subDays(self::hardBlockAfterDays() - 1);
    }

    /** Dia em que um atraso desde `$since` vira acesso limitado. */
    public static function limitedFrom(CarbonInterface $since): Carbon
    {
        return Carbon::instance($since)->startOfDay()->addDays(self::softBlockAfterDays());
    }

    /** Dia em que um atraso desde `$since` vira bloqueio total. */
    public static function blockedFrom(CarbonInterface $since): Carbon
    {
        return Carbon::instance($since)->startOfDay()->addDays(self::hardBlockAfterDays());
    }
}
