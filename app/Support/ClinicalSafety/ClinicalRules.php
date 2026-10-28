<?php

declare(strict_types=1);

namespace App\Support\ClinicalSafety;

/**
 * Leitura das regras clínicas do arquivo de dados `config/clinical_safety.php`
 * (Fase 2 / A2 e A4) — termos já normalizados (TermNormalizer::core) e textos
 * no idioma da tela (pt_BR como reserva).
 */
final class ClinicalRules
{
    /**
     * Regras de risco ocular por princípio ativo.
     *
     * @return array<string, array{key: string, group: string, alert: string, source: string, ingredients: list<string>}>
     */
    public static function riskRules(): array
    {
        $rules = [];

        foreach ((array) config('clinical_safety.ocular_risk_rules', []) as $key => $rule) {
            $rules[$key] = [
                'key'         => (string) $key,
                'group'       => self::localized($rule['group'] ?? []),
                'alert'       => self::localized($rule['alert'] ?? []),
                'source'      => (string) ($rule['source'] ?? ''),
                'ingredients' => self::coreList($rule['ingredients'] ?? []),
                // Risco depois de SUSPENDER: true = sempre; int = por N dias; null = não.
                'persists_after_stop' => match (true) {
                    ($rule['persists_after_stop'] ?? false) === true                                 => true,
                    is_int($rule['persists_after_stop'] ?? null) && $rule['persists_after_stop'] > 0 => $rule['persists_after_stop'],
                    default                                                                          => null,
                },
            ];
        }

        return $rules;
    }

    /**
     * Classes de alergia (membros + apelidos da classe).
     *
     * @return array<string, array{key: string, label: string, source: string, aliases: list<string>, members: list<string>, eye_drops: bool}>
     */
    public static function allergyClasses(): array
    {
        $classes = [];

        foreach ((array) config('clinical_safety.allergy_classes', []) as $key => $class) {
            $classes[$key] = [
                'key'       => (string) $key,
                'label'     => self::localized($class['label'] ?? []),
                'source'    => (string) ($class['source'] ?? ''),
                'aliases'   => self::coreList($class['aliases'] ?? []),
                'members'   => self::coreList($class['members'] ?? []),
                'eye_drops' => (bool) ($class['eye_drops'] ?? false),
            ];
        }

        return $classes;
    }

    /** @param array<string, string> $texts */
    public static function localized(array $texts): string
    {
        return (string) ($texts[app()->getLocale()] ?? $texts['pt_BR'] ?? reset($texts) ?: '');
    }

    /**
     * @param array<int, string> $terms
     *
     * @return list<string>
     */
    private static function coreList(array $terms): array
    {
        $out = [];

        foreach ($terms as $term) {
            $core = TermNormalizer::core((string) $term);

            if ($core !== '') {
                $out[$core] = true;
            }
        }

        return array_keys($out);
    }
}
