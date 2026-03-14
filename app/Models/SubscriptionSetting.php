<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SubscriptionSetting extends Model
{
    protected $table = 'system_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_public',
    ];

    // ── Helpers estáticos ────────────────────────────────────────────────────

    /**
     * Lê uma configuração pelo key, com cache de 10 minutos.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember("subscription_setting:{$key}", 600, function () use ($key, $default) {
            return static::where('key', $key)->value('value') ?? $default;
        });
    }

    /**
     * Grava (ou atualiza) uma configuração e invalida o cache.
     */
    public static function setValue(string $key, mixed $value, ?string $description = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            array_filter(['value' => (string) $value, 'description' => $description], fn ($v) => $v !== null)
        );

        Cache::forget("subscription_setting:{$key}");
    }

    /**
     * Dias de trial padrão (configurável pelo admin).
     */
    public static function trialDays(): int
    {
        return (int) static::getValue('trial_days', 7);
    }

    /**
     * Dias de período de graça após expiração.
     */
    public static function gracePeriodDays(): int
    {
        return (int) static::getValue('grace_period_days', 3);
    }
}
