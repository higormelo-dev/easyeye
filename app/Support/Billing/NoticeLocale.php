<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Http\Middleware\SetLocale;
use App\Models\{Entity, User};

/**
 * Idioma dos avisos do SaaS para a clínica (régua, fim do teste grátis,
 * cobrança enviada pelo manager): o do destinatário; sem preferência, o da
 * empresa; senão o padrão do app.
 */
final class NoticeLocale
{
    public static function for(User $user, ?Entity $entity = null): string
    {
        $supported = array_keys(SetLocale::getSupportedLocales());

        foreach ([$user->locale, $entity?->locale] as $locale) {
            if (filled($locale) && in_array($locale, $supported, true)) {
                return (string) $locale;
            }
        }

        return (string) config('app.locale', 'pt_BR');
    }
}
