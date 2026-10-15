<?php

declare(strict_types=1);

namespace App\Support\Surgery;

use Illuminate\Support\Facades\File;

/**
 * Textos do módulo cirúrgico entregues às telas Vue num único objeto `t`.
 *
 * Núcleo = lang/<locale>/surgery.php (chaves na raiz). Cada etapa tem o próprio
 * arquivo `surgery_<etapa>.php` e vira uma chave de segundo nível com o nome do
 * sufixo: `lang/pt_BR/surgery_schedule.php` → `t.schedule.*`. O arquivo de
 * rótulos de enum (`surgery_enums.php`) fica fora — enum já chega traduzido.
 */
final class SurgeryLang
{
    /** @return array<string, mixed> */
    public static function all(): array
    {
        $texts = (array) trans('surgery');

        foreach (self::moduleNames() as $module) {
            $texts[$module] = (array) trans("surgery_{$module}");
        }

        return $texts;
    }

    /** @return list<string> sufixos de lang/<locale>/surgery_*.php (fallback do app incluído) */
    private static function moduleNames(): array
    {
        $names = [];

        foreach ([app()->getLocale(), (string) config('app.fallback_locale', 'pt_BR'), 'pt_BR'] as $locale) {
            $dir = lang_path($locale);

            if (! File::isDirectory($dir)) {
                continue;
            }

            foreach (File::glob($dir . '/surgery_*.php') ?: [] as $file) {
                $name = substr(basename($file, '.php'), strlen('surgery_'));

                if ($name !== 'enums') {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }
}
