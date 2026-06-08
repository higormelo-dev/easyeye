<?php

namespace App\Enums;

use Carbon\Carbon;

enum BillingCycle: string
{
    case Monthly    = 'monthly';
    case Quarterly  = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly     = 'yearly';
    case Lifetime   = 'lifetime';

    public function label(): string
    {
        return match ($this) {
            self::Monthly    => __('subscriptions.billing_cycle.monthly'),
            self::Quarterly  => __('subscriptions.billing_cycle.quarterly'),
            self::Semiannual => __('subscriptions.billing_cycle.semiannual'),
            self::Yearly     => __('subscriptions.billing_cycle.yearly'),
            self::Lifetime   => __('subscriptions.billing_cycle.lifetime'),
        };
    }

    /** Data de expiração a partir de agora; null para Lifetime. */
    public function addToNow(): ?Carbon
    {
        return match ($this) {
            self::Monthly    => now()->addMonth(),
            self::Quarterly  => now()->addMonths(3),
            self::Semiannual => now()->addMonths(6),
            self::Yearly     => now()->addYear(),
            self::Lifetime   => null,
        };
    }

    /** Número de meses do ciclo (0 = vitalício). */
    public function months(): int
    {
        return match ($this) {
            self::Monthly    => 1,
            self::Quarterly  => 3,
            self::Semiannual => 6,
            self::Yearly     => 12,
            self::Lifetime   => 0,
        };
    }

    /** Nome do intervalo para APIs (month / year / lifetime). */
    public function intervalName(): string
    {
        return match ($this) {
            self::Monthly, self::Quarterly, self::Semiannual => 'month',
            self::Yearly   => 'year',
            self::Lifetime => 'lifetime',
        };
    }

    /** Quantidade de intervalos (para gateways que aceitam interval + count). */
    public function intervalCount(): int
    {
        return match ($this) {
            self::Monthly    => 1,
            self::Quarterly  => 3,
            self::Semiannual => 6,
            self::Yearly     => 1,
            self::Lifetime   => 0,
        };
    }

    /** Código do ciclo no padrão Asaas. */
    public function toAsaas(): string
    {
        return match ($this) {
            self::Monthly    => 'MONTHLY',
            self::Quarterly  => 'QUARTERLY',
            self::Semiannual => 'SEMIANNUALLY',
            self::Yearly     => 'YEARLY',
            self::Lifetime   => 'MONTHLY',
        };
    }
}
